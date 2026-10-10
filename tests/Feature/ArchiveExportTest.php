<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ArchiveExport;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use App\Models\User;
use App\Services\ArchiveExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ArchiveExportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('arch_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'hr_' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);
    }

    /**
     * Test admin mengakses halaman index ekspor arsip tahunan yang telah dipensiunkan (HTTP 410).
     */
    public function test_admin_can_access_archive_exports_index(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.archive-exports.index'));

        $response->assertStatus(410);
    }

    /**
     * Test non-admin tidak diizinkan mengakses halaman ekspor arsip.
     */
    public function test_non_admin_cannot_access_archive_exports(): void
    {
        // HR Staff -> redirect ke dashboard HR dengan notifikasi
        $responseHr = $this->actingAs($this->hrStaff)->get(route('admin.archive-exports.index'));
        $responseHr->assertRedirect(route('hr.dashboard'));

        // Pemagang -> redirect ke dashboard user
        $responsePemagang = $this->actingAs($this->pemagang)->get(route('admin.archive-exports.index'));
        $responsePemagang->assertRedirect(route('user.dashboard'));

        // Permintaan JSON dari non-admin -> 403 Forbidden
        $responseJson = $this->actingAs($this->hrStaff)->getJson(route('admin.archive-exports.index'));
        $responseJson->assertStatus(403);
    }


    /**
     * Test API preview hitungan data tahunan via AJAX yang telah dipensiunkan (HTTP 410).
     */
    public function test_admin_can_get_preview_counts_via_json_api(): void
    {
        $currentYear = (int) date('Y');

        $response = $this->actingAs($this->admin)->getJson(route('admin.archive-exports.preview', ['year' => $currentYear]));

        $response->assertStatus(410);
    }

    /**
     * Test service menghasilkan berkas ZIP yang valid berisi CSV ber-BOM UTF-8 dan manifest JSON.
     */
    public function test_service_generates_valid_zip_with_utf8_bom_csv_and_manifest(): void
    {
        $testYear = 2025;

        // Siapkan dummy data untuk tahun 2025
        $institution = Institution::create([
            'name'                 => 'SMK Arsip Test ' . $this->unique,
            'address'              => 'Jl. Uji Arsip No. 1',
            'phone'                => '081234567890',
            'email'                => 'smk_arsip_' . $this->unique . '@test.com',
            'correspondence_unit'  => 'Hubin',
            'is_active'            => true,
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Drs. Guru Penguji',
            'position_title' => 'Koordinator PKL',
            'phone'          => '081298765432',
            'email'          => 'guru_' . $this->unique . '@test.com',
            'is_active'      => true,
            'created_at'     => Carbon::create($testYear, 3, 15, 10, 0, 0),
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => $testYear,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'started_at'             => Carbon::create($testYear, 4, 1, 9, 0, 0),
            'completed_at'           => Carbon::create($testYear, 4, 10, 15, 0, 0),
        ]);

        $mou = Mou::create([
            'institution_id'                  => $institution->id,
            'followup_cycle_id'               => $cycle->id,
            'company_mou_number'              => 'MOU-TEST-' . $this->unique,
            'first_party_institution_name'    => $institution->name,
            'first_party_signatory_name'      => $contact->name,
            'first_party_signatory_title'     => $contact->position_title,
            'second_party_company_name'       => 'SEVEN INC',
            'second_party_signatory_name'     => 'Rekso Pamungkas',
            'second_party_signatory_title'    => 'Direktur Utama',
            'agreement_place'                 => 'Yogyakarta',
            'agreement_date'                  => Carbon::create($testYear, 5, 2, 0, 0, 0),
            'effective_start'                 => Carbon::create($testYear, 5, 2, 0, 0, 0),
            'effective_end'                   => Carbon::create($testYear + 1, 5, 2, 0, 0, 0),
            'status'                          => Mou::STATUS_ACTIVE,
            'rendered_document_html'          => '<p>Isi MOU Test</p>',
        ]);

        /** @var ArchiveExportService $service */
        $service = app(ArchiveExportService::class);
        $archive = $service->generateArchive($testYear, $this->admin);

        $this->assertInstanceOf(ArchiveExport::class, $archive);
        $this->assertEquals($testYear, $archive->archive_year);
        $this->assertEquals(ArchiveExport::STATUS_COMPLETED, $archive->status);
        $this->assertTrue(Storage::disk('local')->exists($archive->file_path));

        // Verifikasi konten berkas ZIP menggunakan ZipArchive
        $zipFullPath = Storage::disk('local')->path($archive->file_path);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipFullPath) === true);

        // Pastikan berkas-berkas penting ada di dalam ZIP
        $this->assertNotFalse($zip->locateName("pemagang-{$testYear}.csv"));
        $this->assertNotFalse($zip->locateName("guru-dosen-{$testYear}.csv"));
        $this->assertNotFalse($zip->locateName("follow-up-{$testYear}.csv"));
        $this->assertNotFalse($zip->locateName("institusi-mou-{$testYear}.csv"));
        $this->assertNotFalse($zip->locateName("manifest.json"));

        // Periksa UTF-8 BOM pada salah satu berkas CSV
        $csvContent = $zip->getFromName("institusi-mou-{$testYear}.csv");
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent, "CSV harus diawali UTF-8 BOM");
        $this->assertStringContainsString($mou->company_mou_number, $csvContent);

        // Periksa struktur manifest JSON
        $manifestRaw = $zip->getFromName("manifest.json");
        $manifest = json_decode($manifestRaw, true);
        $this->assertIsArray($manifest);
        $this->assertEquals($testYear, $manifest['archive_year']);
        $this->assertEquals('SevenInc-IMS-V7', $manifest['system_version']);

        $zip->close();

        // Bersihkan berkas setelah pengujian
        $service->deleteArchive($archive);
    }

    /**
     * Test admin memicu pembuatan arsip via POST endpoint yang telah dipensiunkan (HTTP 410).
     */
    public function test_admin_can_generate_archive_via_post_request(): void
    {
        $testYear = 2024;

        $response = $this->actingAs($this->admin)->post(route('admin.archive-exports.generate'), [
            'year' => $testYear,
        ]);

        $response->assertStatus(410);
    }

    /**
     * Test admin dapat mengunduh berkas ZIP arsip.
     */
    public function test_admin_can_download_archive_zip(): void
    {
        $testYear = 2023;
        /** @var ArchiveExportService $service */
        $service = app(ArchiveExportService::class);
        $archive = $service->generateArchive($testYear, $this->admin);

        $response = $this->actingAs($this->admin)->get(route('admin.archive-exports.download', $archive));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/zip');

        // Bersihkan berkas setelah test
        $service->deleteArchive($archive);
    }

    /**
     * Test penghapusan arsip melalui endpoint telah dipensiunkan (HTTP 410).
     */
    public function test_admin_can_delete_archive_and_file(): void
    {
        $testYear = 2022;
        /** @var ArchiveExportService $service */
        $service = app(ArchiveExportService::class);
        $archive = $service->generateArchive($testYear, $this->admin);

        $this->assertTrue(Storage::disk('local')->exists($archive->file_path));

        $response = $this->actingAs($this->admin)->delete(route('admin.archive-exports.destroy', $archive));

        $response->assertStatus(410);

        // Record dan file tetap ada
        $this->assertTrue(Storage::disk('local')->exists($archive->file_path));
        $this->assertDatabaseHas('archive_exports', ['id' => $archive->id]);

        $service->deleteArchive($archive);
    }

    /**
     * Test log aktivitas tercatat saat arsip diekspor dan dihapus.
     */
    public function test_activity_logs_recorded_when_archive_exported_and_deleted(): void
    {
        $testYear = 2026;
        /** @var ArchiveExportService $service */
        $service = app(ArchiveExportService::class);
        $archive = $service->generateArchive($testYear, $this->admin);

        // Verifikasi log archive_exported tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'archive_exported',
            'actor_user_id' => $this->admin->id,
        ]);

        $service->deleteArchive($archive, $this->admin);

        // Verifikasi log archive_deleted tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'archive_deleted',
            'actor_user_id' => $this->admin->id,
        ]);
    }
}
