<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LegacyImportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('leg_test_', true);

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
     * Test admin mengakses halaman impor data legacy yang telah dipensiunkan (HTTP 410).
     */
    public function test_admin_can_access_legacy_import_index(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.legacy-import.index'));

        $response->assertStatus(410);
    }

    /**
     * Test non-admin tidak diizinkan mengakses halaman impor legacy.
     */
    public function test_non_admin_cannot_access_legacy_import(): void
    {
        $responseHr = $this->actingAs($this->hrStaff)->get(route('admin.legacy-import.index'));
        $responseHr->assertRedirect(route('hr.dashboard'));

        $responsePemagang = $this->actingAs($this->pemagang)->get(route('admin.legacy-import.index'));
        $responsePemagang->assertRedirect(route('user.dashboard'));

        $responseJson = $this->actingAs($this->hrStaff)->getJson(route('admin.legacy-import.index'));
        $responseJson->assertStatus(403);
    }

    /**
     * Test unduhan template CSV impor legacy telah dipensiunkan (HTTP 410).
     */
    public function test_admin_can_download_csv_template(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.legacy-import.template'));

        $response->assertStatus(410);
    }

    /**
     * Test pengunggahan berkas CSV preview ditolak (HTTP 410).
     */
    public function test_admin_can_preview_legacy_csv_upload(): void
    {
        $csvContent = "nama_institusi,alamat_institusi\n\"SMK Test\",\"Jl. Test\"";
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_prev_');
        file_put_contents($tempFile, $csvContent);

        $uploadedFile = new UploadedFile(
            $tempFile,
            'data_legacy_test.csv',
            'text/csv',
            null,
            true
        );

        $response = $this->actingAs($this->admin)->post(route('admin.legacy-import.preview'), [
            'file' => $uploadedFile,
        ]);

        $response->assertStatus(410);

        @unlink($tempFile);
    }

    /**
     * Test commit data staging ke database ditolak (HTTP 410).
     */
    public function test_admin_can_commit_staging_data_to_database(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.legacy-import.commit'));

        $response->assertStatus(410);
    }

    /**
     * Test pembatalan sesi staging ditolak (HTTP 410).
     */
    public function test_admin_can_cancel_staging_session(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.legacy-import.cancel'));

        $response->assertStatus(410);
    }
}
