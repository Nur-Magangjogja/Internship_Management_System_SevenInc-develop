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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StabilizationPhaseS12Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('s12_', true);

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
     * S12-1: Menu Ekspor Arsip Tahunan dan Impor Data Legacy tidak lagi tampil di Admin Sidebar.
     */
    public function test_admin_sidebar_does_not_contain_retired_archive_and_legacy_import_menus(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('Ekspor Arsip Tahunan', $content);
        $this->assertStringNotContainsString('Impor Data Legacy', $content);
        $this->assertStringNotContainsString(route('admin.archive-exports.index'), $content);
        $this->assertStringNotContainsString(route('admin.legacy-import.index'), $content);
    }

    /**
     * S12-2: Seluruh endpoint mutasi Legacy Import ditolak dengan HTTP 410 (Gone).
     */
    public function test_all_legacy_import_mutation_endpoints_are_retired_and_reject_with_410(): void
    {
        $instCountBefore = Institution::count();
        $contactCountBefore = InstitutionContact::count();

        // 1. Preview
        $dummyCsv = UploadedFile::fake()->createWithContent('test.csv', "nama_institusi\nSMK Test");
        $previewResp = $this->actingAs($this->admin)->post(route('admin.legacy-import.preview'), [
            'file' => $dummyCsv,
        ]);
        $previewResp->assertStatus(410);

        // 2. Commit
        $commitResp = $this->actingAs($this->admin)->post(route('admin.legacy-import.commit'));
        $commitResp->assertStatus(410);

        // 3. Cancel
        $cancelResp = $this->actingAs($this->admin)->post(route('admin.legacy-import.cancel'));
        $cancelResp->assertStatus(410);

        // 4. Index & Template
        $indexResp = $this->actingAs($this->admin)->get(route('admin.legacy-import.index'));
        $indexResp->assertStatus(410);

        $templateResp = $this->actingAs($this->admin)->get(route('admin.legacy-import.template'));
        $templateResp->assertStatus(410);

        // Pastikan tidak ada data baru yang tercipta
        $this->assertEquals($instCountBefore, Institution::count());
        $this->assertEquals($contactCountBefore, InstitutionContact::count());
    }

    /**
     * S12-3: Endpoint Archive Export (generate, sync-drive, destroy, preview, index) ditolak dengan HTTP 410 (Gone).
     */
    public function test_archive_export_mutations_and_sync_are_retired_and_reject_with_410(): void
    {
        $archiveCountBefore = ArchiveExport::count();

        // 1. Generate
        $genResp = $this->actingAs($this->admin)->post(route('admin.archive-exports.generate'), [
            'year' => 2025,
        ]);
        $genResp->assertStatus(410);

        // 2. Preview
        $prevResp = $this->actingAs($this->admin)->get(route('admin.archive-exports.preview', ['year' => 2025]));
        $prevResp->assertStatus(410);

        // 3. Index
        $idxResp = $this->actingAs($this->admin)->get(route('admin.archive-exports.index'));
        $idxResp->assertStatus(410);

        // Buat dummy record historis untuk menguji sync-drive dan destroy
        $historicalArchive = ArchiveExport::create([
            'public_uuid'     => (string) Str::uuid(),
            'archive_year'    => 2023,
            'file_name'       => 'historical-archive-2023.zip',
            'file_path'       => 'archives/historical-archive-2023.zip',
            'file_size_bytes' => 1024,
            'status'          => ArchiveExport::STATUS_COMPLETED,
            'generated_by'    => $this->admin->id,
            'generated_at'    => now()->subYear(),
        ]);

        // 4. Sync Drive
        $syncResp = $this->actingAs($this->admin)->post(route('admin.archive-exports.sync-drive', $historicalArchive));
        $syncResp->assertStatus(410);

        // 5. Destroy
        $destroyResp = $this->actingAs($this->admin)->delete(route('admin.archive-exports.destroy', $historicalArchive));
        $destroyResp->assertStatus(410);

        // Pastikan record historis tetap ada dan tidak dihapus
        $this->assertDatabaseHas('archive_exports', ['id' => $historicalArchive->id]);
    }

    /**
     * S12-4: Direct request ke endpoint pensiun tidak dapat menghasilkan file atau baris baru di DB.
     */
    public function test_direct_requests_to_retired_routes_cannot_create_files_or_records(): void
    {
        $initialArchiveCount = ArchiveExport::count();

        // Coba generate berulang kali
        for ($i = 0; $i < 3; $i++) {
            $resp = $this->actingAs($this->admin)->post(route('admin.archive-exports.generate'), ['year' => 2024 + $i]);
            $resp->assertStatus(410);
        }

        $this->assertEquals($initialArchiveCount, ArchiveExport::count());
    }

    /**
     * S12-5: Unduhan arsip historis oleh Admin tetap berfungsi dan tercatat di activity log.
     */
    public function test_historical_archive_download_by_admin_remains_functional_and_logged(): void
    {
        $dummyPath = 'archives/test-historical-' . $this->unique . '.zip';
        Storage::disk('local')->put($dummyPath, 'PK dummy zip content');

        $archive = ArchiveExport::create([
            'public_uuid'     => (string) Str::uuid(),
            'archive_year'    => 2024,
            'file_name'       => 'test-historical-' . $this->unique . '.zip',
            'file_path'       => $dummyPath,
            'file_size_bytes' => 21,
            'status'          => ArchiveExport::STATUS_COMPLETED,
            'generated_by'    => $this->admin->id,
            'generated_at'    => now()->subMonths(6),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.archive-exports.download', $archive));
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/zip');

        // Pastikan tercatat di activity log
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'archive_downloaded',
            'actor_user_id' => $this->admin->id,
            'subject_id'    => $archive->id,
        ]);

        if (Storage::disk('local')->exists($dummyPath)) {
            Storage::disk('local')->delete($dummyPath);
        }
    }

    /**
     * S12-6: Guest, Pemagang, dan HR Staff tidak dapat mengunduh arsip historis Admin.
     */
    public function test_unauthorized_users_guest_pemagang_hr_staff_cannot_download_historical_archives(): void
    {
        $dummyPath = 'archives/unauth-test-' . $this->unique . '.zip';
        Storage::disk('local')->put($dummyPath, 'PK dummy zip content');

        $archive = ArchiveExport::create([
            'public_uuid'     => (string) Str::uuid(),
            'archive_year'    => 2024,
            'file_name'       => 'unauth-test-' . $this->unique . '.zip',
            'file_path'       => $dummyPath,
            'file_size_bytes' => 21,
            'status'          => ArchiveExport::STATUS_COMPLETED,
            'generated_by'    => $this->admin->id,
            'generated_at'    => now()->subMonths(6),
        ]);

        // Guest
        $guestResp = $this->get(route('admin.archive-exports.download', $archive));
        $guestResp->assertRedirect();

        // Pemagang
        $pemagangResp = $this->actingAs($this->pemagang)->get(route('admin.archive-exports.download', $archive));
        $pemagangResp->assertRedirect(route('user.dashboard'));

        // HR Staff
        $hrResp = $this->actingAs($this->hrStaff)->get(route('admin.archive-exports.download', $archive));
        $hrResp->assertRedirect(route('hr.dashboard'));

        if (Storage::disk('local')->exists($dummyPath)) {
            Storage::disk('local')->delete($dummyPath);
        }
    }

    /**
     * S12-7: Data dan file historis tetap terpelihara (zero destructive mutation).
     */
    public function test_historical_archive_records_and_files_are_strictly_preserved(): void
    {
        $dummyPath = 'archives/preserve-test-' . $this->unique . '.zip';
        Storage::disk('local')->put($dummyPath, 'PK preserved zip content');

        $archive = ArchiveExport::create([
            'public_uuid'     => (string) Str::uuid(),
            'archive_year'    => 2023,
            'file_name'       => 'preserve-test-' . $this->unique . '.zip',
            'file_path'       => $dummyPath,
            'file_size_bytes' => 24,
            'status'          => ArchiveExport::STATUS_COMPLETED,
            'generated_by'    => $this->admin->id,
            'generated_at'    => now()->subYear(),
        ]);

        // Percobaan delete via endpoint ditolak
        $delResp = $this->actingAs($this->admin)->delete(route('admin.archive-exports.destroy', $archive));
        $delResp->assertStatus(410);

        // File dan record database tetap eksis
        $this->assertTrue(Storage::disk('local')->exists($dummyPath));
        $this->assertDatabaseHas('archive_exports', [
            'id'         => $archive->id,
            'deleted_at' => null,
        ]);

        if (Storage::disk('local')->exists($dummyPath)) {
            Storage::disk('local')->delete($dummyPath);
        }
    }

    /**
     * S12-8: Alur bisnis inti S1–S11 (institusi, follow-up, MOU, spreadsheet sync) tetap berjalan normal.
     */
    public function test_core_business_flows_remain_intact_after_retirement(): void
    {
        $institution = Institution::create([
            'name'                 => 'SMK Mitra Tetap ' . $this->unique,
            'address'              => 'Jl. Normal No. 1',
            'phone'                => '0274-123123',
            'email'                => 'mitra_' . $this->unique . '@test.com',
            'correspondence_unit'  => 'Hubin',
            'is_active'            => true,
        ]);

        $this->assertDatabaseHas('institutions', ['id' => $institution->id]);

        // Verifikasi route operasional admin tetap dapat diakses
        $adminDashboard = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $adminDashboard->assertStatus(200);

        $adminInstitutions = $this->actingAs($this->admin)->get(route('admin.institutions.index'));
        $adminInstitutions->assertStatus(200);

        $adminSpreadsheets = $this->actingAs($this->admin)->get(route('admin.spreadsheet-connections.index'));
        $adminSpreadsheets->assertStatus(200);
    }
}
