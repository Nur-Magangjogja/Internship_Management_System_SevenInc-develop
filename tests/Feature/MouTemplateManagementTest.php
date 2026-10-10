<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Institution;
use App\Models\MouTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MouTemplateManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('mou_', true);

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

    public function test_admin_can_view_mou_template_list()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.index'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.index');
        $response->assertSee('Template MOU');
    }

    public function test_admin_can_view_create_mou_template_page()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.create'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.create');
        $response->assertSee('[institution_name]');
    }

    public function test_admin_can_create_new_mou_template_and_activity_logged()
    {
        // Pastikan template lama sudah ada
        $existing = MouTemplate::create([
            'name'                 => 'Existing Template ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Existing</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $postData = [
            'name'                    => 'Template Kemitraan Baru ' . $this->unique,
            'version_number'          => 2,
            'template_html'           => '<h1>SURAT PERJANJIAN [company_name] & [institution_name]</h1><p>Nomor: [company_mou_number]</p>',
            'company_name'            => 'PT SEVEN INC INDONESIA',
            'company_address'         => 'Jl. Ring Road Utara, Sleman',
            'company_phone'           => '0274-123456',
            'company_email'           => 'hr@seveninc.com',
            'company_signatory_name'  => 'Direktur Utama Test',
            'company_signatory_title' => 'Direktur Utama',
            'agreement_place'         => 'Sleman',
            'is_active'               => '1',
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.mou-templates.store'), $postData);

        $response->assertRedirect(route('admin.mou-templates.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('mou_templates', [
            'name'           => 'Template Kemitraan Baru ' . $this->unique,
            'version_number' => 2,
            'is_active'      => 1,
        ]);

        // Template lama harus otomatis dinonaktifkan
        $this->assertDatabaseHas('mou_templates', [
            'id'        => $existing->id,
            'is_active' => 0,
        ]);

        // Activity log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_created',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_view_edit_mou_template_page()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Edit Test ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Konten Test</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.edit', $template));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.edit');
        $response->assertSee('Template Edit Test ' . $this->unique);
    }

    public function test_admin_can_update_mou_template_and_activity_logged()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Sebelum Diubah ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Versi Awal</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        $updateData = [
            'name'                    => 'Template Setelah Diubah ' . $this->unique,
            'version_number'          => 2,
            'template_html'           => '<p>Versi Revisi</p>',
            'company_name'            => 'SEVEN INC REVISED',
            'company_address'         => 'Yogyakarta Baru',
            'company_phone'           => '08123456789',
            'company_email'           => 'partnership@seveninc.com',
            'company_signatory_name'  => 'Signatory Baru',
            'company_signatory_title' => 'Chief Executive Officer',
            'agreement_place'         => 'Yogyakarta',
        ];

        $response = $this->actingAs($this->admin)->put(route('admin.mou-templates.update', $template), $updateData);

        $response->assertRedirect(route('admin.mou-templates.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('mou_templates', [
            'id'             => $template->id,
            'name'           => 'Template Setelah Diubah ' . $this->unique,
            'version_number' => 2,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_updated',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_toggle_template_active_state()
    {
        $template1 = MouTemplate::create([
            'name'                 => 'Template Satu ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Satu</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $template2 = MouTemplate::create([
            'name'                 => 'Template Dua ' . $this->unique,
            'version_number'       => 2,
            'template_html'        => '<p>Dua</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        // Aktifkan template 2
        $response = $this->actingAs($this->admin)->post(route('admin.mou-templates.toggle-active', $template2));

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('mou_templates', [
            'id'        => $template2->id,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('mou_templates', [
            'id'        => $template1->id,
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_activated',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_preview_mou_template_with_rendered_placeholders()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template Preview ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<div>Mitra: [institution_name] | Perusahaan: [company_name]</div>',
            'allowed_placeholders' => ['institution_name', 'company_name'],
            'default_values'       => ['company_name' => 'SEVEN INC REKSA'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.mou-templates.preview', $template));

        $response->assertStatus(200);
        $response->assertViewIs('admin.mou-templates.preview');
        // Harus me-render konten yang di-parse tokennya (default mock institution name atau nama perusahaan)
        $response->assertSee('SEVEN INC REKSA');
        $response->assertSee('UNIVERSITAS CONTOH INDONESIA');
    }

    public function test_admin_can_soft_delete_mou_template_and_activity_logged()
    {
        $template = MouTemplate::create([
            'name'                 => 'Template To Delete ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<p>Hapus saya</p>',
            'allowed_placeholders' => ['institution_name'],
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.mou-templates.destroy', $template));

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        // Pastikan ter-soft delete
        $this->assertSoftDeleted('mou_templates', [
            'id' => $template->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_template_deleted',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_hr_staff_and_pemagang_cannot_manage_mou_templates()
    {
        // HR staff coba akses index template (role:admin only middleware)
        $responseHr = $this->actingAs($this->hrStaff)->get(route('admin.mou-templates.index'));
        $this->assertTrue(in_array($responseHr->status(), [403, 302]));

        // Pemagang coba akses index template
        $responsePemagang = $this->actingAs($this->pemagang)->get(route('admin.mou-templates.index'));
        $this->assertTrue(in_array($responsePemagang->status(), [403, 302]));
    }

    public function test_guest_cannot_access_mou_templates_and_is_redirected()
    {
        $responseGuest = $this->get(route('admin.mou-templates.index'));
        $responseGuest->assertRedirect(route('user.login'));
    }
}
