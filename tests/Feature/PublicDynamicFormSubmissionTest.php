<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\User;
use App\Services\FormBuilderService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicDynamicFormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected FormBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FormBuilderService::class);
        Http::preventStrayRequests();
        Mail::fake();

        \App\Models\Division::firstOrCreate(
            ['name' => 'Programmer (Front End / Backend)'],
            ['slug' => 'programmer', 'is_active' => true]
        );
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

    protected function validRegistrationPayload(array $overrides = []): array
    {
        return array_merge([
            'fullname'                 => 'Budi Santoso',
            'student_id'               => '2026101301',
            'born_date'                => '2002-05-15',
            'gender'                   => 'Laki-laki',
            'phone_number'             => '081234567890',
            'email'                    => 'budi@example.com',
            'institution_name'         => 'Universitas Gadjah Mada',
            'faculty'                  => 'Fakultas Teknik',
            'study_program'            => 'Teknologi Informasi',
            'current_city'             => 'Yogyakarta',
            'internship_reason'        => 'Mencari pengalaman industri nyata',
            'internship_interest'      => 'Programmer (Front End / Backend)',
            'start_date'               => '2026-03-01',
            'end_date'                 => '2026-06-01',
            'internship_type'          => 'campus',
            'current_status'           => 'Student',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'has_laptop'               => '1',
            'planned_duration'         => '3 bulan',
            'cv_ktp_portofolio_pdf'    => UploadedFile::fake()->create('dokumen_syarat.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    /**
     * 1. Render hanya published active schema; draft, inactive, dan archived tidak tampil sebagai pertanyaan baru.
     */
    public function test_render_only_published_active_schema_fields(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Buat draf dengan field custom
        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_link_github',
            'field_type'  => 'url',
            'label'       => 'Link Repository GitHub',
            'is_required' => true,
        ]);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_hidden_draft',
            'field_type'  => 'text',
            'label'       => 'Draft Pertanyaan Rahasia',
            'is_required' => false,
        ]);

        // Sebelum dipublikasikan: form publik tidak boleh menampilkan custom field dari draf
        $resBefore = $this->actingAs($pemagang)->get(route('pemagang.registration.form'));
        $resBefore->assertOk();
        $resBefore->assertDontSee('Link Repository GitHub');
        $resBefore->assertDontSee('Draft Pertanyaan Rahasia');

        // Publikasikan versi 1
        $published = $this->service->publishVersion($draft, $admin);

        // Tambahkan field inactive & archived pada versi baru yang tidak boleh tampil
        $newDraft = $this->service->getOrCreateDraft(2026, $admin);
        $fieldInactive = $this->service->addFieldToDraft($newDraft, [
            'field_key'   => 'custom_inactive_field',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Nonaktif',
            'is_active'   => false,
        ]);
        $this->service->archiveField($fieldInactive);

        // Form publik sekarang harus merender field dari published schema v1
        $resAfter = $this->actingAs($pemagang)->get(route('pemagang.registration.form'));
        $resAfter->assertOk();
        $resAfter->assertSee('Link Repository GitHub');
        $resAfter->assertSee('Pertanyaan Tambahan');
        $resAfter->assertSee('name="form_schema_version" value="' . $published->version_code . '"', false);
        $resAfter->assertDontSee('Pertanyaan Nonaktif');
    }

    /**
     * 2. Pengurutan, grup, required, dan Blade escaping pada label/helper text.
     */
    public function test_field_types_ordering_group_and_blade_escaping(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_xss_label',
            'field_type'  => 'text',
            'label'       => '<script>alert("XSS")</script> Portofolio',
            'helper_text' => '<b>Gunakan tag tebal</b>',
            'is_required' => true,
            'sort_order'  => 5,
        ]);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_pilihan_opsi',
            'field_type'  => 'select',
            'label'       => 'Pilih Ukuran Kaos',
            'options'     => [
                ['value' => 'M', 'label' => 'Ukuran Medium'],
                ['value' => 'L', 'label' => 'Ukuran Large'],
            ],
            'is_required' => true,
            'sort_order'  => 10,
        ]);

        $this->service->publishVersion($draft, $admin);

        $response = $this->actingAs($pemagang)->get(route('pemagang.registration.form'));
        $response->assertOk();

        // Label harus di-escape secara aman (tidak mentah sebagai tag script)
        $response->assertDontSee('<script>alert("XSS")</script>', false);
        $response->assertSee('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt; Portofolio', false);
        $response->assertSee('&lt;b&gt;Gunakan tag tebal&lt;/b&gt;', false);

        // Opsi select dirender dengan benar
        $response->assertSee('Ukuran Medium');
        $response->assertSee('Ukuran Large');
    }

    /**
     * 3. Otorisasi dan penolakan input field_key ilegal / tidak dikenal (mass assignment protection).
     */
    public function test_rejection_of_illegal_field_keys(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_legit_key',
            'field_type'  => 'text',
            'label'       => 'Catatan Khusus',
            'is_required' => false,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        $payload = $this->validRegistrationPayload([
            'form_schema_version'    => $published->version_code,
            'custom_legit_key'       => 'Jawaban sah yang diizinkan',
            'custom_injected_hacker' => 'DROP TABLE users; malicious data',
            'arbitrary_extra_field'  => 'should be discarded',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        // Jawaban sah tersimpan
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'custom_legit_key',
            'answer_value' => 'Jawaban sah yang diizinkan',
        ]);

        // Key ilegal tidak pernah masuk ke registration_custom_answers
        $this->assertDatabaseMissing('registration_custom_answers', [
            'field_key' => 'custom_injected_hacker',
        ]);
        $this->assertDatabaseMissing('registration_custom_answers', [
            'field_key' => 'arbitrary_extra_field',
        ]);
    }

    /**
     * 4. Validasi opsi select, radio, dan checkbox: menolak nilai tidak dikenal.
     */
    public function test_validation_of_select_radio_and_checkbox_options(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_pilihan_shift',
            'field_type'  => 'radio',
            'label'       => 'Pilihan Shift Kerja',
            'options'     => [
                ['value' => 'pagi', 'label' => 'Shift Pagi'],
                ['value' => 'siang', 'label' => 'Shift Siang'],
            ],
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Kirim nilai opsi ilegal (tidak ada di daftar)
        $invalidPayload = $this->validRegistrationPayload([
            'form_schema_version'  => $published->version_code,
            'custom_pilihan_shift' => 'malam_ilegal',
        ]);

        $resInvalid = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $invalidPayload);
        $resInvalid->assertSessionHasErrors(['custom_pilihan_shift']);

        // Kirim nilai sah
        $validPayload = $this->validRegistrationPayload([
            'form_schema_version'  => $published->version_code,
            'custom_pilihan_shift' => 'pagi',
        ]);

        $resValid = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $validPayload);
        $resValid->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'custom_pilihan_shift',
            'answer_value' => 'pagi',
            'answer_label' => 'Shift Pagi',
        ]);
    }

    /**
     * 5. Submisi dengan versi lama yang masih sah (dalam grace period):
     *    - Berhasil.
     *    - Required fields dari versi baru TIDAK berlaku retroaktif.
     *    - Snapshot mencatat versi lama.
     */
    public function test_submit_with_archived_version_within_grace_period_succeeds(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 1. Publikasikan Versi 1
        $draftV1 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draftV1, [
            'field_key'   => 'custom_v1_notes',
            'field_type'  => 'text',
            'label'       => 'Catatan Versi 1',
            'is_required' => true,
        ]);
        $v1 = $this->service->publishVersion($draftV1, $admin);

        // 2. Publikasikan Versi 2 yang menambahkan required field baru
        $draftV2 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draftV2, [
            'field_key'   => 'custom_v2_mandatory_link',
            'field_type'  => 'url',
            'label'       => 'Tautan Portofolio Baru Wajib V2',
            'is_required' => true,
        ]);
        $v2 = $this->service->publishVersion($draftV2, $admin);

        // Set grace period eksplisit 60 menit
        $this->service->setGracePeriodMinutes(60);

        // 3. Pemagang submit menggunakan versi v1 (yang sudah diarsipkan namun masih dalam grace period)
        // Tanpa mengisi field baru v2 'custom_v2_mandatory_link'
        $payloadV1 = $this->validRegistrationPayload([
            'form_schema_version' => $v1->version_code,
            'custom_v1_notes'     => 'Isian sesuai formulir v1',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadV1);
        $res->assertSessionHasNoErrors();

        // Verifikasi snapshot menunjuk versi v1 dan hash v1
        $this->assertDatabaseHas('registration_form_snapshots', [
            'form_schema_id'      => $v1->id,
            'form_schema_version' => $v1->version_code,
            'schema_hash'         => $v1->schema_hash,
        ]);

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'custom_v1_notes',
            'answer_value' => 'Isian sesuai formulir v1',
        ]);
    }

    /**
     * 6. Submisi versi kedaluwarsa (di luar grace period) gagal dengan preservasi input pengguna.
     */
    public function test_submit_with_expired_version_fails_with_preserved_inputs(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Publikasikan v1 dan v2
        $draftV1 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draftV1, [
            'field_key'   => 'custom_bio',
            'field_type'  => 'textarea',
            'label'       => 'Biografi Singkat',
            'is_required' => true,
        ]);
        $v1 = $this->service->publishVersion($draftV1, $admin);

        $draftV2 = $this->service->getOrCreateDraft(2026, $admin);
        $v2 = $this->service->publishVersion($draftV2, $admin);

        // Atur grace period 5 menit dan majukan waktu 10 menit agar pasti kedaluwarsa
        $this->service->setGracePeriodMinutes(5);
        \Carbon\Carbon::setTestNow(now()->addMinutes(10));

        // Jalankan pengiriman dengan versi v1 yang sudah kedaluwarsa
        $payloadExpired = $this->validRegistrationPayload([
            'form_schema_version' => $v1->version_code,
            'custom_bio'          => 'Tulisan biografi yang sangat panjang dan berharga...',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadExpired);

        // Reset waktu tes
        \Carbon\Carbon::setTestNow();

        // Harus gagal dengan pesan versi expired
        $res->assertSessionHasErrors(['form_version']);

        // Data input pengguna wajib dipreservasi dalam session (flash input)
        $this->assertEquals('Tulisan biografi yang sangat panjang dan berharga...', session('_old_input.custom_bio'));
        $this->assertEquals('Budi Santoso', session('_old_input.fullname'));

        // Tidak ada pendaftaran yang terbuat untuk pemagang ini
        $this->assertEquals(0, InternshipRegistration::where('user_id', $pemagang->id)->count());
        $this->assertDatabaseMissing('internship_registrations', ['user_id' => $pemagang->id]);
    }

    /**
     * 7. Submisi atomik: Registration, snapshot, dan custom_answers tersimpan konsisten.
     */
    public function test_atomic_registration_snapshot_and_answers(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_bidang_keahlian',
            'field_type'  => 'text',
            'label'       => 'Bidang Keahlian',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        $payload = $this->validRegistrationPayload([
            'form_schema_version'    => $published->version_code,
            'custom_bidang_keahlian' => 'Machine Learning & DevOps',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        // 1 registration, 1 snapshot, 1 answer untuk pemagang ini
        $this->assertEquals(1, InternshipRegistration::where('user_id', $pemagang->id)->count());
        $reg = InternshipRegistration::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('waiting', $reg->internship_status);
        $this->assertDatabaseHas('registration_form_snapshots', ['registration_id' => $reg->id]);
        $this->assertDatabaseHas('registration_custom_answers', ['registration_id' => $reg->id]);

        $snapshot = RegistrationFormSnapshot::where('registration_id', $reg->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertEquals($published->version_code, $snapshot->form_schema_version);
        $this->assertEquals($published->schema_hash, $snapshot->schema_hash);

        $answer = RegistrationCustomAnswer::where('registration_id', $reg->id)->first();
        $this->assertNotNull($answer);
        $this->assertEquals('custom_bidang_keahlian', $answer->field_key);
        $this->assertEquals('Machine Learning & DevOps', $answer->answer_value);
    }

    /**
     * 8. Rollback saat validasi custom field atau penyimpanan gagal (zero data separuh jalan).
     */
    public function test_transaction_rollback_when_save_fails(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_required_field',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Wajib',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Kirim core data valid namun custom_required_field kosong
        $payloadMissingCustom = $this->validRegistrationPayload([
            'form_schema_version'   => $published->version_code,
            'custom_required_field' => '', // kosong
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadMissingCustom);
        $res->assertSessionHasErrors(['custom_required_field']);

        // Pastikan zero database mutations untuk pendaftar ini
        $this->assertEquals(0, InternshipRegistration::where('user_id', $pemagang->id)->count());
        $this->assertDatabaseMissing('internship_registrations', ['user_id' => $pemagang->id]);
        $this->assertDatabaseMissing('registration_form_snapshots', ['form_schema_id' => $published->id]);
        $this->assertDatabaseMissing('registration_custom_answers', ['field_key' => 'custom_required_field']);
    }

    /**
     * 9. Imutabilitas snapshot setelah edit jawaban oleh Admin.
     */
    public function test_immutable_snapshot_after_custom_answers_edit(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_skor_tes',
            'field_type'  => 'text',
            'label'       => 'Skor Tes Awal',
            'is_required' => false,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Submit pendaftaran
        $payload = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_skor_tes'     => '85',
        ]);
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);

        $reg = InternshipRegistration::first();
        $snapshot = $reg->formSnapshot;
        $originalSnapshotJson = json_encode($snapshot->schema_snapshot);
        $originalHash = $snapshot->schema_hash;

        // Admin mengoreksi jawaban peserta
        $resAdminUpdate = $this->actingAs($admin)->patchJson(route('admin.interns.custom-answers.update', $reg), [
            'answers' => [
                'custom_skor_tes' => '95',
            ],
        ]);
        $resAdminUpdate->assertOk();
        $this->assertTrue($resAdminUpdate->json('success'));

        // Jawaban berubah
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'custom_skor_tes',
            'answer_value'    => '95',
        ]);

        // Snapshot sama sekali tidak berubah
        $freshSnapshot = $snapshot->fresh();
        $this->assertEquals($originalSnapshotJson, json_encode($freshSnapshot->schema_snapshot));
        $this->assertEquals($originalHash, $freshSnapshot->schema_hash);

        // Memanggil update() langsung pada RegistrationFormSnapshot melempar DomainException
        $this->expectException(DomainException::class);
        $freshSnapshot->update(['schema_hash' => 'tampered_hash']);
    }

    /**
     * 10. Otorisasi edit jawaban: Admin boleh, pemagang hanya boleh pada draft/waiting, ditolak saat accepted.
     */
    public function test_admin_and_pemagang_authorization_for_editing_custom_answers(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_info',
            'field_type'  => 'text',
            'label'       => 'Info',
            'is_required' => false,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // 1. Pemagang submit awal (status waiting)
        $payload = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_info'         => 'Nilai awal',
        ]);
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);

        $reg = InternshipRegistration::where('user_id', $pemagang->id)->first();
        $this->assertEquals('waiting', $reg->internship_status);

        // 2. Pemagang masih boleh update saat status waiting
        $payloadUpdate = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_info'         => 'Nilai diperbarui oleh pemagang',
        ]);
        $resUpdate = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadUpdate);
        $resUpdate->assertSessionHasNoErrors();
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'custom_info',
            'answer_value'    => 'Nilai diperbarui oleh pemagang',
        ]);

        // 3. Admin menerima pemagang (status menjadi accepted)
        $reg->update(['internship_status' => 'accepted']);

        // 4. Pemagang mencoba mengubah kembali setelah accepted -> DITOLAK
        $payloadAfterAccepted = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_info'         => 'Mencoba manipulasi setelah diterima',
        ]);
        $resForbidden = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadAfterAccepted);
        $resForbidden->assertSessionHasErrors(['status']);

        // Nilai tetap tidak berubah
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'custom_info',
            'answer_value'    => 'Nilai diperbarui oleh pemagang',
        ]);

        // 5. Admin berwenang tetap dapat melakukan koreksi resmi
        $resAdmin = $this->actingAs($admin)->patchJson(route('admin.interns.custom-answers.update', $reg), [
            'answers' => [
                'custom_info' => 'Koreksi resmi oleh admin',
            ],
        ]);
        $resAdmin->assertOk();
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'custom_info',
            'answer_value'    => 'Koreksi resmi oleh admin',
        ]);
    }

    /**
     * 11. Existing registration tanpa custom fields tetap berfungsi normal (backward compatibility).
     */
    public function test_existing_registration_without_custom_fields_works_seamlessly(): void
    {
        $pemagang = $this->createPemagang();

        // Tidak ada skema yang dipublikasikan (fallback form statis / default)
        $payload = $this->validRegistrationPayload();

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        $this->assertEquals(1, InternshipRegistration::where('user_id', $pemagang->id)->count());
        $reg = InternshipRegistration::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('Budi Santoso', $reg->fullname);
        $this->assertEquals('waiting', $reg->internship_status);

        // Tidak ada snapshot atau custom answers yang bocor
        $this->assertDatabaseMissing('registration_form_snapshots', ['registration_id' => $reg->id]);
        $this->assertDatabaseMissing('registration_custom_answers', ['registration_id' => $reg->id]);
    }

    /**
     * 12. Model-level immutability hooks:
     *     - Direct Eloquent update/delete pada published FormField melempar DomainException.
     *     - Direct delete pada published FormSchema melempar DomainException.
     *     - Direct delete pada locked FormFieldRegistry melempar DomainException.
     */
    public function test_direct_eloquent_mutations_on_published_models_are_blocked(): void
    {
        $admin = $this->createAdmin();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $field = $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_guarded_key',
            'field_type'  => 'text',
            'label'       => 'Guarded Field',
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        $freshField = $field->fresh();
        $this->assertNotNull($freshField);

        // 1. Mutasi langsung FormField via Eloquent harus throw DomainException
        try {
            $freshField->update(['label' => 'Direct Tampered Label']);
            $this->fail('Expected DomainException was not thrown on direct FormField update');
        } catch (DomainException $e) {
            $this->assertStringContainsString('imutabel', $e->getMessage());
        }

        // 2. Penghapusan langsung FormField via Eloquent harus throw DomainException
        try {
            $freshField->delete();
            $this->fail('Expected DomainException was not thrown on direct FormField delete');
        } catch (DomainException $e) {
            $this->assertStringContainsString('dilarang dihapus', $e->getMessage());
        }

        // 3. Penghapusan langsung FormSchema via Eloquent harus throw DomainException
        try {
            $published->delete();
            $this->fail('Expected DomainException was not thrown on direct FormSchema delete');
        } catch (DomainException $e) {
            $this->assertStringContainsString('dilarang dihapus', $e->getMessage());
        }

        // 4. Penghapusan langsung FormFieldRegistry yang terkunci harus throw DomainException
        $registry = FormFieldRegistry::where('field_key', 'custom_guarded_key')->first();
        $this->assertNotNull($registry);
        $this->assertTrue($registry->is_locked);

        try {
            $registry->delete();
            $this->fail('Expected DomainException was not thrown on locked FormFieldRegistry delete');
        } catch (DomainException $e) {
            $this->assertStringContainsString('dilarang dihapus', $e->getMessage());
        }
    }
}
