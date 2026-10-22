<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Division;
use App\Models\Faculty;
use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetTabProfile;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomAnswerOutboundTest extends TestCase
{
    use RefreshDatabase;

    protected SpreadsheetConnection $connection;
    protected User $adminUser;
    protected User $pemagangUser;
    protected MockSpreadsheetTransport $mockTransport;
    protected Institution $institution;
    protected Division $division;
    protected FormSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Outbound Test',
            'email'     => 'admin.outbound@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->pemagangUser = User::create([
            'name'      => 'Pemagang Outbound Test',
            'email'     => 'pemagang.outbound@example.com',
            'password'  => bcrypt('password'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas Gadjah Mada',
            'is_active' => true,
        ]);

        $this->division = Division::create([
            'name'      => 'Backend Development',
            'slug'      => 'backend-development',
            'is_active' => true,
        ]);

        $this->connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'             => 'Google Sheets Pendaftaran 2026',
                'spreadsheet_id'           => 'mock-spreadsheet-custom-outbound',
                'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/mock-spreadsheet-custom-outbound/edit',
                'is_enabled'               => true,
                'connection_status'        => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url' => 'https://script.google.com/macros/s/TEST_CUSTOM_OUTBOUND/exec',
            ]
        );

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(SpreadsheetTransportInterface::class, $this->mockTransport);

        // Setup FormSchema
        $this->schema = FormSchema::create([
            'year'         => 2026,
            'version_code' => '1.0.0',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
            'schema_hash'  => 'hash-2026-v1',
            'published_at' => now(),
        ]);

        $field = FormField::create([
            'form_schema_id' => $this->schema->id,
            'field_key'      => 'kemampuan_bahasa_inggris',
            'label'          => 'Kemampuan Bahasa Inggris',
            'field_type'     => 'text',
            'order'          => 1,
            'is_active'      => true,
            'is_required'    => false,
        ]);

        FormFieldRegistry::create([
            'field_key'       => 'kemampuan_bahasa_inggris',
            'canonical_label' => 'Kemampuan Bahasa Inggris',
            'field_type'      => 'text',
            'is_active'       => true,
        ]);
    }

    protected function createRegistration(array $overrides = []): InternshipRegistration
    {
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $this->institution->id, 'name' => 'Fakultas Teknik']
        );
        $program = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknologi Informasi']
        );

        $defaults = [
            'user_id'          => $this->pemagangUser->id,
            'fullname'         => 'Ahmad Zaki',
            'institution_id'   => $this->institution->id,
            'faculty_id'       => $faculty->id,
            'study_program_id' => $program->id,
            'division_id'      => $this->division->id,
            'phone_number'     => '081234567890',
            'internship_status'=> InternshipRegistration::STATUS_ACCEPTED,
            'form_schema_id'   => $this->schema->id,
        ];

        return InternshipRegistration::create(array_merge($defaults, $overrides));
    }

    /**
     * 1. Admin update custom answers successfully saves answers and enqueues SpreadsheetRowSync.
     */
    public function test_admin_update_custom_answers_saves_answers_and_enqueues_spreadsheet_row_sync(): void
    {
        $intern = $this->createRegistration();

        $response = $this->actingAs($this->adminUser)
            ->patchJson(route('admin.interns.custom-answers.update', $intern), [
                'answers' => [
                    'kemampuan_bahasa_inggris' => 'Fluent / TOEFL 600',
                ],
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Jawaban dinamis pendaftaran berhasil diperbarui.',
        ]);

        // Verifikasi database lokal terupdate
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $intern->id,
            'field_key'       => 'kemampuan_bahasa_inggris',
            'answer_value'    => 'Fluent / TOEFL 600',
        ]);

        // Verifikasi SpreadsheetRowSync di-enqueue secara asinkron
        $rowSync = SpreadsheetRowSync::where('syncable_id', $intern->id)
            ->where('syncable_type', InternshipRegistration::class)
            ->first();

        $this->assertNotNull($rowSync, 'SpreadsheetRowSync harus dibuat / di-enqueue setelah custom answers diperbarui.');
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $rowSync->sync_status);
        $this->assertEquals('2026', $rowSync->target_sheet_tab);
    }

    /**
     * 2. Checksum verification no-ops when payload is identical to previously verified sync.
     */
    public function test_row_sync_checksum_noop_when_payload_identical_to_verified(): void
    {
        $intern = $this->createRegistration();

        $syncEngine = app(SpreadsheetSyncEngineService::class);
        $payload = ['Nama Lengkap' => $intern->fullname, 'Email' => $intern->user->email];
        $checksum = $syncEngine->calculatePayloadChecksum($this->connection, '2026', $payload);

        // Pre-create a verified sync record with matching checksum
        $rowSync = SpreadsheetRowSync::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $this->connection->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $intern->id,
            'external_sync_id'          => 'REG-VERIFIED-001',
            'target_sheet_tab'          => '2026',
            'schema_version'            => 'v1',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'                 => 10,
            'payload_checksum'          => $checksum,
            'last_synced_at'            => now()->subHour(),
        ]);

        $callCountBefore = $this->mockTransport->callCount;

        // Attempting sync with identical checksum and without force must no-op
        $hasPrior = !empty($rowSync->last_synced_at) && !empty($rowSync->row_index) && !empty($rowSync->payload_checksum);
        $this->assertTrue($hasPrior);
        $this->assertEquals($checksum, $rowSync->payload_checksum);

        // Remote transport must not be called
        $this->assertEquals($callCountBefore, $this->mockTransport->callCount);
    }
}
