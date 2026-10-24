<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ArchiveExport;
use App\Models\Institution;
use App\Models\Mou;
use App\Models\User;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloudStorageSyncTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->unique = uniqid('cloud_test_', true);

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
     * Test local fallback storage driver dapat menyimpan berkas dan menghasilkan data sinkronisasi simulasi.
     */
    public function test_local_fallback_driver_can_upload_and_simulate_cloud_sync(): void
    {
        $driver = new LocalStorageFallbackDriver();
        $this->assertTrue($driver->isConfigured());
        $this->assertEquals('local_fallback', $driver->getDriverName());

        $tempFile = tempnam(sys_get_temp_dir(), 'test_fb_');
        file_put_contents($tempFile, 'Simulasi isi berkas dokumen');

        $result = $driver->uploadFile($tempFile, 'Institusi/smk-test/MOU', 'mou-test-signed.pdf');

        $this->assertNotEmpty($result['file_id']);
        $this->assertStringContainsString('local_sim_', $result['file_id']);
        $this->assertNotEmpty($result['web_view_link']);
        $this->assertEquals('mou-test-signed.pdf', $result['file_name']);

        @unlink($tempFile);
    }

    /**
     * Test GoogleAppsScriptDriveDriver menangani unggah berkas via Google Apps Script Drive Gateway.
     */
    public function test_apps_script_drive_driver_handles_file_upload_with_signed_gateway(): void
    {
        Config::set('services.google_apps_script.enabled', true);
        Config::set('services.google_apps_script.endpoint_url', 'https://script.google.com/macros/s/AKfy_test_gas/exec');
        Config::set('services.google_apps_script.shared_secret', 'test_secret_123');
        Config::set('services.google_apps_script.timeout_seconds', 30);
        Config::set('services.google_apps_script.root_folder_id', 'test_root_folder_123');

        Http::fake([
            'https://script.google.com/macros/s/AKfy_test_gas/exec*' => Http::response([
                'success'         => true,
                'action'          => 'drive_upload',
                'file_id'         => 'mocked_gas_drive_file_id_789',
                'file_name'       => 'mou-signed-final.pdf',
                'web_view_link'   => 'https://drive.google.com/file/d/mocked_gas_drive_file_id_789/view',
                'checksum_sha256' => hash('sha256', 'Isi naskah dokumen PDF asli'),
            ], 200),
        ]);

        $driver = new GoogleAppsScriptDriveDriver();

        $this->assertTrue($driver->isConfigured());
        $this->assertEquals('google_apps_script_drive', $driver->getDriverName());

        $tempFile = tempnam(sys_get_temp_dir(), 'test_gas_');
        file_put_contents($tempFile, 'Isi naskah dokumen PDF asli');

        $upload = $driver->uploadFile($tempFile, 'Institusi/smk-test/MOU', 'mou-signed-final.pdf');

        $this->assertEquals('mocked_gas_drive_file_id_789', $upload['file_id']);
        $this->assertEquals('https://drive.google.com/file/d/mocked_gas_drive_file_id_789/view', $upload['web_view_link']);
        $this->assertEquals('google_apps_script_drive', $upload['driver']);

        @unlink($tempFile);
    }

    /**
     * Test CloudSyncManager menangani paket arsip tahunan sesuai ketentuan Addendum V4 (deactivated).
     */
    public function test_cloud_sync_manager_syncs_archive_export_and_updates_database(): void
    {
        $testYear = 2026;
        $archiveFileName = "test-archive-{$this->unique}.zip";
        $archivePath = "archives/{$archiveFileName}";

        Storage::disk('local')->put($archivePath, 'Konten arsip zip simulasi');

        $archive = ArchiveExport::create([
            'public_uuid'     => (string) Str::uuid(),
            'archive_year'    => $testYear,
            'file_name'       => $archiveFileName,
            'file_path'       => $archivePath,
            'file_size_bytes' => 1024,
            'status'          => ArchiveExport::STATUS_COMPLETED,
            'generated_by'    => $this->admin->id,
            'generated_at'    => now(),
        ]);

        $manager = new CloudSyncManager(new LocalStorageFallbackDriver());
        $result = $manager->syncArchiveExport($archive, $this->admin);

        $this->assertTrue($result['success']);
        $this->assertEquals('deactivated', $result['driver']);
        $this->assertStringContainsString('telah dinonaktifkan', $result['message']);

        // Bersihkan berkas
        if (Storage::disk('local')->exists($archivePath)) {
            Storage::disk('local')->delete($archivePath);
        }
    }

    /**
     * Test CloudSyncManager menyinkronkan naskah MOU yang ditandatangani dan mengupdate database.
     */
    public function test_cloud_sync_manager_syncs_mou_signed_document_and_updates_database(): void
    {
        $institution = Institution::create([
            'name'                 => 'Institut Teknologi Cloud ' . $this->unique,
            'address'              => 'Jl. Cloud No. 10',
            'phone'                => '081122334455',
            'email'                => 'it_cloud_' . $this->unique . '@test.com',
            'correspondence_unit'  => 'Kerjasama',
            'is_active'            => true,
        ]);

        $signedRelativePath = "mou_signed/mou_signed_{$this->unique}.pdf";
        Storage::disk('public')->put($signedRelativePath, 'Konten fisik naskah MOU PDF yang ditandatangani');

        $mou = Mou::create([
            'institution_id'               => $institution->id,
            'company_mou_number'           => 'MOU-SYNC-' . $this->unique,
            'first_party_institution_name' => $institution->name,
            'first_party_signatory_name'   => 'Dr. Rektor Cloud',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'effective_start'              => now()->toDateString(),
            'effective_end'                => now()->addYear()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_file_path'             => $signedRelativePath,
            'rendered_document_html'       => '<p>Naskah MOU</p>',
        ]);

        $mockDriver = $this->createMock(\App\Services\CloudStorage\CloudStorageProviderInterface::class);
        $mockDriver->method('getDriverName')->willReturn('google_apps_script_drive');
        $mockDriver->method('uploadFile')->willReturn([
            'file_id'       => 'mocked_mou_drive_id_123',
            'web_view_link' => 'https://drive.google.com/file/d/mocked_mou_drive_id_123/view',
            'driver'        => 'google_apps_script_drive',
        ]);
        $manager = new CloudSyncManager($mockDriver);
        $result = $manager->syncMou($mou, $this->admin);

        $mou->refresh();
        $this->assertTrue($mou->is_drive_synced);
        $this->assertNotEmpty($mou->drive_file_id);
        $this->assertNotEmpty($mou->drive_web_view_link);
        $this->assertNotNull($mou->drive_synced_at);

        // Verifikasi log audit activity_logs tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_drive_synced',
            'actor_user_id' => $this->admin->id,
        ]);

        // Bersihkan berkas
        if (Storage::disk('public')->exists($signedRelativePath)) {
            Storage::disk('public')->delete($signedRelativePath);
        }
    }

    /**
     * Test admin dapat memicu sinkronisasi arsip melalui POST endpoint.
     */
    public function test_admin_can_trigger_archive_sync_via_post_endpoint(): void
    {
        $testYear = 2025;
        $archiveFileName = "test-post-archive-{$this->unique}.zip";
        $archivePath = "archives/{$archiveFileName}";

        Storage::disk('local')->put($archivePath, 'Isi zip testing endpoint');

        $archive = ArchiveExport::create([
            'public_uuid'     => (string) Str::uuid(),
            'archive_year'    => $testYear,
            'file_name'       => $archiveFileName,
            'file_path'       => $archivePath,
            'file_size_bytes' => 512,
            'status'          => ArchiveExport::STATUS_COMPLETED,
            'generated_by'    => $this->admin->id,
            'generated_at'    => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.archive-exports.sync-drive', $archive));

        $response->assertStatus(410);

        if (Storage::disk('local')->exists($archivePath)) {
            Storage::disk('local')->delete($archivePath);
        }
    }

    /**
     * Test admin dan HR dapat memicu sinkronisasi dokumen MOU melalui POST endpoint.
     */
    public function test_admin_and_hr_can_trigger_mou_sync_via_post_endpoint(): void
    {
        $institution = Institution::create([
            'name'                 => 'Universitas Sync ' . $this->unique,
            'address'              => 'Jl. Uji Sync No. 5',
            'phone'                => '082233445566',
            'email'                => 'univ_sync_' . $this->unique . '@test.com',
            'correspondence_unit'  => 'Humas',
            'is_active'            => true,
        ]);

        $signedRelativePath = "mou_signed/mou_signed_ep_{$this->unique}.pdf";
        Storage::disk('public')->put($signedRelativePath, 'Isi berkas PDF testing endpoint');

        $mou = Mou::create([
            'institution_id'               => $institution->id,
            'company_mou_number'           => 'MOU-EP-' . $this->unique,
            'first_party_institution_name' => $institution->name,
            'first_party_signatory_name'   => 'Prof. Humas',
            'first_party_signatory_title'  => 'Wakil Rektor IV',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'effective_start'              => now()->toDateString(),
            'effective_end'                => now()->addYear()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_file_path'             => $signedRelativePath,
            'rendered_document_html'       => '<p>Naskah MOU</p>',
        ]);

        // HR Staff dapat melakukan sinkronisasi
        $responseHr = $this->actingAs($this->hrStaff)->post(route('hr.mous.sync-drive', $mou));
        $responseHr->assertRedirect();
        $responseHr->assertSessionHas('success');

        $mou->refresh();
        $this->assertTrue($mou->is_drive_synced);

        if (Storage::disk('public')->exists($signedRelativePath)) {
            Storage::disk('public')->delete($signedRelativePath);
        }
    }
}
