<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Faculty;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\RegistrationContactLink;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FollowUpSpreadsheetMappingService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetConnectionService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SyncMonitoringQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrandUniversalSchemaAndFollowUpGatingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Brand $defaultBrand;
    protected Brand $targetBrand;
    protected Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Tester',
            'email'     => 'admin.test@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
        ]);

        $this->defaultBrand = Brand::create([
            'name'              => 'SEVEN INC',
            'code'              => 'SI',
            'registration_host' => 'daftar.seveninc.web.id',
            'is_default'        => true,
        ]);

        $this->targetBrand = Brand::create([
            'name'              => 'Ada Kamar',
            'code'              => 'AKM',
            'registration_host' => 'daftar.adakamar.id',
            'is_default'        => false,
        ]);

        $this->institution = Institution::create([
            'name'      => 'SMK Negeri 2 Surabaya',
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: ensureCanonicalSchemaForConnection menginisialisasi skema 2026 kanonikal 38 kolom pada koneksi brand.
     */
    public function test_universal_schema_provisioning_for_brand_connection(): void
    {
        $connService = app(SpreadsheetConnectionService::class);
        $connService->ensureBrandConnectionsExist();

        $targetConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $this->targetBrand->id)
            ->first();

        $this->assertNotNull($targetConn, 'Koneksi registrasi untuk brand target harus ada.');

        $schema = $connService->ensureCanonicalSchemaForConnection($targetConn, 2026);

        $this->assertNotNull($schema);
        $this->assertEquals(2026, $schema->year);
        $this->assertEquals('2026', $schema->sheet_tab_name);
        $this->assertEquals('v1', $schema->schema_version);
        $this->assertTrue($schema->is_active);
        $this->assertEquals(38, $schema->columnMappings()->count(), 'Skema harus memiliki 38 kolom kanonikal.');
    }

    /**
     * Test 2: Pendaftar pada brand target berhasil membangun payload 38 kolom (tidak lagi melempar error schema missing).
     */
    public function test_registration_on_transferred_brand_builds_payload_successfully(): void
    {
        $connService = app(SpreadsheetConnectionService::class);
        $connService->ensureBrandConnectionsExist();

        $targetConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $this->targetBrand->id)
            ->first();
        $connService->ensureCanonicalSchemaForConnection($targetConn, 2026);

        $faculty = Faculty::create(['institution_id' => $this->institution->id, 'name' => 'Fakultas Vokasi']);
        $prodi = StudyProgram::create(['faculty_id' => $faculty->id, 'name' => 'Multimedia']);

        $user = User::create([
            'name'     => 'Bagus Kurniawan',
            'email'    => 'bagus.k@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $reg = InternshipRegistration::create([
            'user_id'           => $user->id,
            'brand_id'          => $this->targetBrand->id,
            'fullname'          => 'Bagus Kurniawan',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $prodi->id,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'supervisor_name'   => 'Bambang Sutrisno, S.T., M.T.',
            'supervisor_contact'=> '085712345678',
        ]);
        $reg->created_at = Carbon::parse('2026-10-26 10:51:11');
        $reg->saveQuietly();

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $resolvedSchema = $mappingService->getSchemaForRegistration($reg);

        $this->assertNotNull($resolvedSchema, 'getSchemaForRegistration harus menemukan skema untuk brand target.');
        $this->assertEquals(2026, $resolvedSchema->year);

        $payload = $mappingService->buildOutboundRowPayload($reg);
        $this->assertIsArray($payload);
        $this->assertCount(38, $payload);
        $this->assertEquals('Bagus Kurniawan', $payload['Nama Lengkap']);
        $this->assertEquals('bagus.k@example.com', $payload['Email']);
    }

    /**
     * Test 3: Follow-up sync ditahan (RETRYING) jika baris pendaftar terkait sedang berstatus FAILED.
     */
    public function test_followup_sync_is_held_when_linked_registration_sync_failed(): void
    {
        $connService = app(SpreadsheetConnectionService::class);
        $connService->ensureBrandConnectionsExist();

        $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $this->targetBrand->id)
            ->first();
        $connService->ensureCanonicalSchemaForConnection($regConn, 2026);

        $followUpConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();
        $followUpConn->update([
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/testFollowUp/edit',
            'spreadsheet_id'  => 'testFollowUp',
            'is_enabled'      => true,
        ]);

        // Seed followup schema
        $fuSchema = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $followUpConn->id,
            'year'                      => 0,
            'sheet_tab_name'            => 'Follow Up Guru Dosen',
            'schema_version'            => 'v1',
            'is_active'                 => true,
        ]);
        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $fuSchema->id,
            'column_identity'               => 'col_timestamp',
            'column_order'                  => 1,
            'sheet_header'                  => 'Timestamp',
            'target_field'                  => 'created_at',
            'data_type'                     => 'timestamp',
            'direction'                     => 'outbound',
            'is_required'                   => true,
            'mapping_status'                => 'active',
        ]);

        $faculty = Faculty::create(['institution_id' => $this->institution->id, 'name' => 'Fakultas Vokasi']);
        $prodi = StudyProgram::create(['faculty_id' => $faculty->id, 'name' => 'Multimedia']);

        $user = User::create([
            'name'     => 'Bagus Kurniawan',
            'email'    => 'bagus.k2@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $reg = InternshipRegistration::create([
            'user_id'           => $user->id,
            'brand_id'          => $this->targetBrand->id,
            'fullname'          => 'Bagus Kurniawan',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $prodi->id,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);
        $reg->created_at = Carbon::parse('2026-10-26 10:51:11');
        $reg->saveQuietly();

        // Buat kontak pembimbing dan kaitkan
        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Bambang Sutrisno, S.T., M.T.',
            'phone'          => '085712345678',
            'is_active'      => true,
            'is_verified'    => true,
            'verified_at'    => now(),
        ]);

        RegistrationContactLink::create([
            'internship_registration_id' => $reg->id,
            'institution_contact_id'     => $contact->id,
            'relation_year'              => 2026,
            'is_primary'                 => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);

        // Simulasikan baris registrasi pendaftar sedang FAILED di spreadsheet
        $regSync = SpreadsheetRowSync::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $regConn->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'external_sync_id'          => "REG-{$reg->id}-test",
            'target_sheet_tab'          => '2026',
            'sync_status'               => SpreadsheetRowSync::STATUS_FAILED,
            'error_message'             => 'Koneksi timeout',
        ]);

        $syncEngine = app(SpreadsheetSyncEngineService::class);
        $mockTransport = new MockSpreadsheetTransport();

        // Queue follow-up
        $cycleSync = $syncEngine->queueSyncForFollowUpCycle($cycle);
        $this->assertNotNull($cycleSync);

        // Eksekusi worker follow-up saat pendaftar masih failed -> harus DITOLAK / RETRYING, bukan SYNCED
        $result = $syncEngine->processSyncRecord($cycleSync, $mockTransport);
        $this->assertFalse($result, 'Follow-up sync tidak boleh berhasil saat pendaftar terkait berstatus FAILED.');

        $cycleSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $cycleSync->sync_status);
        $this->assertStringContainsString('NOT_ELIGIBLE_PENDING_REGISTRATION_SYNC', $cycleSync->error_message);
    }

    /**
     * Test 4: Monitoring query tidak lagi melabeli FollowUpCycle sebagai Reg #ID.
     */
    public function test_sync_monitoring_disambiguates_followup_cycle_from_registration_id(): void
    {
        $followUpConn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP, 'brand_id' => null],
            ['display_name' => 'Spreadsheet Follow-Up Guru/Dosen']
        );

        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Bambang Sutrisno',
            'phone'          => '085712345678',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $cycleSync = SpreadsheetRowSync::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $followUpConn->id,
            'syncable_type'             => FollowUpCycle::class,
            'syncable_id'               => $cycle->id,
            'external_sync_id'          => "FWU-{$cycle->id}-test",
            'target_sheet_tab'          => 'Follow Up Guru Dosen',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
        ]);

        $monitoringQuery = app(SyncMonitoringQueryService::class);
        $records = $monitoringQuery->getMonitoringRecords(['category' => 'all'], 10, $this->adminUser);

        $item = collect($records->items())->firstWhere('sync_identifier', "FWU-{$cycle->id}-test");
        $this->assertNotNull($item);
        $this->assertNull($item->registration_id, 'Follow-up cycle tidak boleh memiliki registration_id numerik palsu.');
    }

    /**
     * Test 5: Pengeditan calon pemagang (status waiting) hanya memperbarui database dan TIDAK memicu sinkronisasi Spreadsheet.
     */
    public function test_editing_calon_pemagang_only_updates_database_without_spreadsheet_sync(): void
    {
        $user = User::create([
            'name'     => 'Calon Pemagang',
            'email'    => 'calon@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $intern = InternshipRegistration::create([
            'user_id'           => $user->id,
            'brand_id'          => $this->defaultBrand->id,
            'fullname'          => 'Nama Asli Calon',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'phone_number'      => '081234567890',
            'institution_id'    => $this->institution->id,
        ]);

        $this->actingAs($this->adminUser);

        $response = $this->patchJson(route('admin.interns.update', $intern->id), [
            'fullname'     => 'Nama Calon Diedit',
            'phone_number' => '089999999999',
        ]);

        $response->assertStatus(200);

        // Verifikasi database terupdate
        $this->assertDatabaseHas('internship_registrations', [
            'id'           => $intern->id,
            'fullname'     => 'Nama Calon Diedit',
            'phone_number' => '089999999999',
        ]);

        // Verifikasi TIDAK ADA SpreadsheetRowSync yang terbuat untuk calon pemagang ini
        $syncCount = SpreadsheetRowSync::where('syncable_type', $intern->getMorphClass())
            ->where('syncable_id', $intern->id)
            ->count();
        $this->assertEquals(0, $syncCount, 'Calon pemagang yang diedit tidak boleh membuat antrean atau baris Spreadsheet.');
    }

    /**
     * Test 6: Pengeditan pemagang yang sudah berstatus diterima (accepted) memicu sinkronisasi Spreadsheet.
     */
    public function test_editing_accepted_intern_triggers_spreadsheet_sync(): void
    {
        $connService = app(SpreadsheetConnectionService::class);
        $connService->ensureBrandConnectionsExist();

        $conn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $this->defaultBrand->id)
            ->first();
        $conn->update([
            'spreadsheet_id'    => 'mock-default-brand-id',
            'spreadsheet_url'   => 'https://docs.google.com/spreadsheets/d/mock-default-brand-id/edit',
            'is_enabled'        => true,
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);
        $connService->ensureCanonicalSchemaForConnection($conn, 2026);

        $user = User::create([
            'name'     => 'Pemagang Diterima',
            'email'    => 'diterima@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $intern = InternshipRegistration::create([
            'user_id'           => $user->id,
            'brand_id'          => $this->defaultBrand->id,
            'fullname'          => 'Pemagang Lama',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'phone_number'      => '081234567890',
            'institution_id'    => $this->institution->id,
        ]);
        $intern->created_at = Carbon::parse('2026-03-01 10:00:00');
        $intern->saveQuietly();

        $this->actingAs($this->adminUser);

        $response = $this->patchJson(route('admin.interns.update', $intern->id), [
            'fullname' => 'Pemagang Diperbarui',
        ]);

        $response->assertStatus(200);

        // Verifikasi database terupdate
        $this->assertDatabaseHas('internship_registrations', [
            'id'       => $intern->id,
            'fullname' => 'Pemagang Diperbarui',
        ]);

        // Verifikasi bahwa SpreadsheetRowSync terbuat untuk pemagang yang sudah diterima
        $syncRecord = SpreadsheetRowSync::where('syncable_type', $intern->getMorphClass())
            ->where('syncable_id', $intern->id)
            ->first();
        $this->assertNotNull($syncRecord, 'Pemagang yang sudah diterima harus memicu antrean SpreadsheetRowSync saat datanya diedit.');
    }
}
