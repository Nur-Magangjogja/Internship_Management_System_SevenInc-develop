<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\FormSubmitSetting;
use App\Models\User;
use App\Services\FormBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSubmitSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected FormBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->service = app(FormBuilderService::class);
    }

    protected function createAdmin(): User
    {
        return User::create([
            'name'      => 'Admin User ' . uniqid(),
            'email'     => 'admin_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);
    }

    protected function createPemagang(): User
    {
        return User::create([
            'name'      => 'Pemagang User ' . uniqid(),
            'email'     => 'pemagang_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);
    }

    public function test_form_submit_settings_default_values(): void
    {
        $settings = FormSubmitSetting::forYear(2026);
        $this->assertEquals('Finalisasi & Submit', $settings->section_title);
        $this->assertTrue($settings->show_notice_box);
        $this->assertEquals('0895 2900 2944', $settings->admin_wa_number);
        $this->assertEquals('SAYA SUDAH ISI FORM', $settings->admin_wa_message);
        $this->assertEquals('Kirim Pendaftaran', $settings->submit_button_text);
        $this->assertTrue($settings->save_draft_enabled);
    }

    public function test_admin_can_save_submit_settings(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.submit-settings'), [
            'year'               => 2026,
            'section_title'      => 'Kirim Berkas & Finalisasi',
            'section_subtitle'   => 'Harap periksa kembali semua data sebelum submit.',
            'show_notice_box'    => 1,
            'notice_title'       => 'PENTING:',
            'notice_text'        => 'Magang Seven Inc adalah program profesional unpaid.',
            'admin_wa_number'    => '081234567890',
            'admin_wa_message'   => 'KONFIRMASI PENDAFTARAN SAYA',
            'submit_button_text' => 'Kirim Formulir Sekarang',
            'save_draft_enabled' => 1,
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok'      => true,
            'message' => 'Pengaturan Area Submit berhasil disimpan.',
        ]);

        $saved = FormSubmitSetting::forYear(2026);
        $this->assertEquals('Kirim Berkas & Finalisasi', $saved->section_title);
        $this->assertEquals('081234567890', $saved->admin_wa_number);
        $this->assertEquals('Kirim Formulir Sekarang', $saved->submit_button_text);
    }

    public function test_admin_can_add_field_to_group_finalisasi_submit(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->post(route('admin.form-settings.fields.store'), [
            'year'        => 2026,
            'label'       => 'Saya menyetujui seluruh tata tertib magang',
            'field_type'  => 'checkbox',
            'group_name'  => FormField::GROUP_FINALISASI_SUBMIT,
            'is_required' => 1,
        ]);

        $response->assertRedirect();

        $field = FormField::where('label', 'Saya menyetujui seluruh tata tertib magang')->first();
        $this->assertNotNull($field);
        $this->assertEquals(FormField::GROUP_FINALISASI_SUBMIT, $field->resolved_group);
    }

    public function test_preview_data_endpoint_returns_finalisasi_submit_and_submit_settings(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->getJson(route('admin.form-settings.fields.preview-data', ['year' => 2026]));

        $response->assertOk();
        $response->assertJsonStructure([
            'ok',
            'grouped_fields' => [
                'data_diri_edukasi',
                'pilihan_durasi',
                'berkas_portofolio',
                'informasi_tambahan',
                'finalisasi_submit',
            ],
            'submit_settings',
        ]);
    }
}
