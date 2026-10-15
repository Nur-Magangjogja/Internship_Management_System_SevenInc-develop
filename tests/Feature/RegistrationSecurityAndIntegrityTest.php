<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\RegistrationAttachment;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\RegistrationSpreadsheetMappingService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistrationSecurityAndIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role = 'pemagang', array $attributes = []): User
    {
        $unique = uniqid('g1_usr_', true);
        return User::create(array_merge([
            'name'      => 'User ' . Str::random(5),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('secret123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createHierarchy(): array
    {
        $unique = Str::random(6);
        $inst = Institution::create([
            'name'            => 'Universitas G1 ' . $unique,
            'normalized_name' => 'universitas g1 ' . $unique,
            'is_active'       => true,
        ]);
        $fac = Faculty::create([
            'name'           => 'Fakultas Teknik ' . $unique,
            'institution_id' => $inst->id,
        ]);
        $prog = StudyProgram::create([
            'name'       => 'Teknik Informatika ' . $unique,
            'faculty_id' => $fac->id,
        ]);
        $city = City::create([
            'name' => 'Yogyakarta ' . $unique,
        ]);
        $div = Division::create([
            'name'        => 'Web Development ' . $unique,
            'slug'        => 'web-development-' . strtolower($unique),
            'quota'       => 10,
            'is_active'   => true,
            'description' => 'Test Division',
        ]);

        return compact('inst', 'fac', 'prog', 'city', 'div');
    }

    /**
     * 1. Private registration documents stored on local disk and downloads require strict authorization.
     */
    public function test_private_registration_documents_stored_on_local_disk_and_download_requires_authorization(): void
    {
        Storage::fake('local');
        $h = $this->createHierarchy();
        $owner = $this->makeUser('user');
        $unrelatedUser = $this->makeUser('user');
        $admin = $this->makeUser('admin');
        $hrStaff = $this->makeUser('hr_staff');

        $pdfFile = UploadedFile::fake()->create('berkas_cv_ktp.pdf', 1024, 'application/pdf');

        $payload = [
            'fullname'                 => 'Pelamar Privat',
            'gender'                   => 'Laki-laki',
            'current_city'             => $h['city']->name,
            'email'                    => $owner->email,
            'phone_number'             => '081234567890',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'NIM-G1-001',
            'institution_name'         => $h['inst']->name,
            'study_program'            => $h['prog']->name,
            'internship_interest'      => $h['div']->name,
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Alasan Magang Privat',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
            'cv_ktp_portofolio_pdf'    => $pdfFile,
        ];

        $res = $this->actingAs($owner)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $owner->id)->first();
        $this->assertNotNull($reg);

        $attachment = $reg->attachments()->where('attachment_type', 'cv_ktp_portofolio')->first();
        $this->assertNotNull($attachment);

        // Harus disimpan pada private storage (disk local), bukan public
        $this->assertTrue(Storage::disk('local')->exists($attachment->file_path));
        $this->assertStringStartsWith('private/registrations/', $attachment->file_path);

        $downloadUrl = route('pemagang.registration.attachment.download', $attachment->id);

        // a. Guest tidak dapat mengunduh (redirect ke login / 401)
        auth()->logout();
        $guestRes = $this->get($downloadUrl);
        $guestRes->assertRedirect(route('user.login'));

        // b. Pengguna lain yang tidak terafiliasi ditolak (403 Forbidden)
        $unrelatedRes = $this->actingAs($unrelatedUser)->get($downloadUrl);
        $unrelatedRes->assertStatus(403);

        // c. Pemilik pendaftaran dapat mengunduh
        $ownerRes = $this->actingAs($owner)->get($downloadUrl);
        $ownerRes->assertStatus(200);

        // d. Admin dapat mengunduh
        $adminRes = $this->actingAs($admin)->get($downloadUrl);
        $adminRes->assertStatus(200);

        // e. HR Staff dapat mengunduh
        $hrRes = $this->actingAs($hrStaff)->get($downloadUrl);
        $hrRes->assertStatus(200);
    }

    /**
     * 2. Legacy public registration documents can still be downloaded seamlessly.
     */
    public function test_legacy_public_registration_documents_can_still_be_downloaded(): void
    {
        Storage::fake('public');
        $h = $this->createHierarchy();
        $owner = $this->makeUser('pemagang');

        $reg = IR::create([
            'user_id'             => $owner->id,
            'fullname'            => 'Legacy Intern',
            'email'               => $owner->email,
            'phone_number'        => '081299998888',
            'city_id'             => $h['city']->id,
            'institution_id'      => $h['inst']->id,
            'faculty_id'          => $h['fac']->id,
            'study_program_id'    => $h['prog']->id,
            'division_id'         => $h['div']->id,
            'internship_status'   => IR::STATUS_WAITING,
            'internship_type'     => 'Magang Mandiri',
            'student_id'          => 'LEGACY-NIM',
            'gender'              => 'Laki-laki',
            'current_status'      => 'Mahasiswa',
            'internship_reason'   => 'Belajar',
            'wfo_readiness'       => 'Siap',
            'schedule_shift_readiness' => 'Sanggup',
            'planned_duration'    => '3 Bulan',
        ]);

        $legacyPath = 'uploads/legacy_cv_file.pdf';
        Storage::disk('public')->put($legacyPath, 'FAKE LEGACY PUBLIC PDF CONTENT');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio',
            'file_path'                  => $legacyPath,
            'original_name'              => 'legacy_cv.pdf',
            'file_size'                  => 100,
            'mime_type'                  => 'application/pdf',
        ]);

        $downloadUrl = route('pemagang.registration.attachment.download', $attachment->id);

        $res = $this->actingAs($owner)->get($downloadUrl);
        $res->assertStatus(200);
    }

    /**
     * 3. Prevent lifecycle regression on draft: Submitted registration (waiting) does not regress to new.
     */
    public function test_draft_save_does_not_regress_submitted_registration_status_to_new(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('pemagang');

        $reg = IR::create([
            'user_id'             => $user->id,
            'fullname'            => 'Submitted Candidate',
            'email'               => $user->email,
            'phone_number'        => '081211112222',
            'city_id'             => $h['city']->id,
            'institution_id'      => $h['inst']->id,
            'faculty_id'          => $h['fac']->id,
            'study_program_id'    => $h['prog']->id,
            'division_id'         => $h['div']->id,
            'internship_status'   => IR::STATUS_WAITING,
            'internship_type'     => 'Magang Mandiri',
            'student_id'          => 'SUBMITTED-01',
            'gender'              => 'Laki-laki',
            'current_status'      => 'Mahasiswa',
            'internship_reason'   => 'Alasan',
            'wfo_readiness'       => 'Siap',
            'schedule_shift_readiness' => 'Sanggup',
            'planned_duration'    => '3 Bulan',
        ]);

        $this->assertEquals(IR::STATUS_WAITING, $reg->internship_status);
        $this->assertFalse($reg->is_draft);

        // Kirim request simpan draft
        $draftPayload = [
            'fullname'            => 'Submitted Candidate Renamed',
            'phone_number'        => '081211112222',
            'internship_interest' => $h['div']->name,
        ];

        $res = $this->actingAs($user)->post(route('pemagang.registration.draft'), $draftPayload);
        $res->assertSessionDoesntHaveErrors();

        $reg->refresh();
        $this->assertEquals('Submitted Candidate Renamed', $reg->fullname);
        // Status tetap STATUS_WAITING, tidak pernah regresi ke STATUS_NEW
        $this->assertEquals(IR::STATUS_WAITING, $reg->internship_status);
        $this->assertFalse($reg->is_draft);
    }

    /**
     * 4. Snapshot & custom answers version consistency: Editing existing submission binds to its snapshot schema version.
     */
    public function test_registration_editing_with_existing_snapshot_binds_to_snapshot_schema_version(): void
    {
        $service = app(FormBuilderService::class);
        $h = $this->createHierarchy();
        $user = $this->makeUser('pemagang');
        $year = rand(2040, 2090);

        // Buat Skema v1 terpublikasi
        $schemaV1 = FormSchema::create([
            'version_code' => "v{$year}.1-draft",
            'year'         => $year,
            'title'        => "Formulir {$year} v1",
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => false,
        ]);
        $service->addFieldToDraft($schemaV1, [
            'field_key'   => 'link_portofolio_v1',
            'field_type'  => 'url',
            'label'       => 'Link Portofolio v1',
            'is_required' => false,
        ]);
        $schemaV1 = $service->publishVersion($schemaV1);

        // Buat registrasi dan bekukan snapshot v1
        $reg = IR::create([
            'user_id'             => $user->id,
            'fullname'            => 'Applicant v1',
            'email'               => $user->email,
            'phone_number'        => '081233334444',
            'city_id'             => $h['city']->id,
            'institution_id'      => $h['inst']->id,
            'faculty_id'          => $h['fac']->id,
            'study_program_id'    => $h['prog']->id,
            'division_id'         => $h['div']->id,
            'internship_status'   => IR::STATUS_WAITING,
            'internship_type'     => 'Magang Mandiri',
            'student_id'          => 'V1-STUDENT',
            'gender'              => 'Laki-laki',
            'current_status'      => 'Mahasiswa',
            'internship_reason'   => 'Alasan',
            'wfo_readiness'       => 'Siap',
            'schedule_shift_readiness' => 'Sanggup',
            'planned_duration'    => '3 Bulan',
        ]);
        $snapshot = $service->freezeSnapshot($reg, $schemaV1);
        $this->assertEquals($schemaV1->version_code, $snapshot->form_schema_version);

        // Sekarang publikasikan Skema v2 sebagai skema aktif baru menggunakan draf yang otomatis dibuat
        $schemaV2Draft = FormSchema::where('year', $year)->where('status', FormSchema::STATUS_DRAFT)->first();
        $service->addFieldToDraft($schemaV2Draft, [
            'field_key'   => 'sertifikasi_khusus_v2',
            'field_type'  => 'text',
            'label'       => 'Sertifikasi Khusus v2',
            'is_required' => true, // Wajib di v2!
        ]);
        $schemaV2 = $service->publishVersion($schemaV2Draft);

        // Tampilkan form: pemagang dengan snapshot v1 melihat field v1, bukan v2
        $resShow = $this->actingAs($user)->get(route('pemagang.registration.form'));
        $resShow->assertStatus(200);
        $resShow->assertViewHas('activeSchema', function ($schema) use ($schemaV1) {
            return $schema->id === $schemaV1->id;
        });

        // Edit dan submit: validasi mengikuti v1, tidak error karena kekurangan sertifikasi_khusus_v2
        $updatePayload = [
            'fullname'                 => 'Applicant v1 Updated',
            'gender'                   => 'Laki-laki',
            'current_city'             => $h['city']->name,
            'email'                    => $user->email,
            'phone_number'             => '081233334444',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'V1-STUDENT',
            'institution_name'         => $h['inst']->name,
            'study_program'            => $h['prog']->name,
            'internship_interest'      => $h['div']->name,
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Alasan',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
            'link_portofolio_v1'       => 'https://example.com/portofolio-v1',
        ];

        $resUpdate = $this->actingAs($user)->post(route('pemagang.registration.store'), $updatePayload);
        $resUpdate->assertSessionDoesntHaveErrors();

        // Snapshot historis tidak bermutasi
        $snapshot->refresh();
        $this->assertEquals($schemaV1->version_code, $snapshot->form_schema_version);
    }

    /**
     * 5. FormSchema & FormField strict immutability.
     */
    public function test_form_schema_and_form_field_strict_immutability(): void
    {
        $service = app(FormBuilderService::class);
        $year = rand(2100, 2190);

        $schema = FormSchema::create([
            'version_code' => "v{$year}.1-draft",
            'year'         => $year,
            'title'        => 'Immutability Test',
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => false,
        ]);
        $field = $service->addFieldToDraft($schema, [
            'field_key'  => 'test_immutable_field',
            'field_type' => 'text',
            'label'      => 'Test Label',
        ]);
        $published = $service->publishVersion($schema);

        // a. FormSchema terpublikasi dilarang kembali ke draft
        try {
            $published->update(['status' => FormSchema::STATUS_DRAFT]);
            $this->fail('Harus melempar DomainException saat mencoba mengubah published ke draft');
        } catch (DomainException $e) {
            $this->assertStringContainsString('dikembalikan ke draf', $e->getMessage());
        }
        $published->refresh();

        // b. FormField pada skema terpublikasi dilarang dimutasi
        try {
            $field->update(['label' => 'Label Diubah']);
            $this->fail('Harus melempar DomainException saat memodifikasi field pada skema published');
        } catch (DomainException $e) {
            $this->assertStringContainsString('bersifat imutabel', $e->getMessage());
        }
        $field->refresh();

        try {
            $field->update(['is_active' => false]);
            $this->fail('Harus melempar DomainException saat menonaktifkan field pada skema published');
        } catch (DomainException $e) {
            $this->assertStringContainsString('bersifat imutabel', $e->getMessage());
        }
        $field->refresh();

        // c. archiveField dilarang pada skema terpublikasi
        try {
            $service->archiveField($field);
            $this->fail('Harus melempar DomainException saat mengarsipkan field langsung pada published');
        } catch (DomainException $e) {
            $this->assertStringContainsString('dilarang diarsipkan langsung', $e->getMessage());
        }

        // d. Skema diarsipkan dilarang diubah kembali ke draft atau dimutasi versinya
        $successorDraft = FormSchema::where('year', $published->year)->where('status', FormSchema::STATUS_DRAFT)->first();
        $service->publishVersion($successorDraft);
        $published->refresh();
        $this->assertTrue($published->isArchived());

        try {
            $published->update(['status' => FormSchema::STATUS_DRAFT]);
            $this->fail('Harus melempar DomainException saat mencoba mengubah archived ke draft');
        } catch (DomainException $e) {
            $this->assertStringContainsString('imutabel permanen', $e->getMessage());
        }

        try {
            $published->update(['version_code' => 'v99.9']);
            $this->fail('Harus melempar DomainException saat mencoba memodifikasi versi skema archived');
        } catch (DomainException $e) {
            $this->assertStringContainsString('imutabel permanen', $e->getMessage());
        }
    }

    /**
     * 6. Atomic registration persistence rolls back master data on failure.
     */
    public function test_atomic_registration_persistence_rolls_back_master_data_on_failure(): void
    {
        $unique = Str::random(8);
        $newCityName = 'Kota Baru ' . $unique;
        $newInstName = 'Institusi Baru ' . $unique;
        $newProgName = 'Prodi Baru ' . $unique;

        $user = $this->makeUser('user');

        $payload = [
            'fullname'                 => 'Pelamar Uji Rollback',
            'gender'                   => 'Laki-laki',
            'current_city'             => $newCityName,
            'email'                    => $user->email,
            'phone_number'             => '081234567899',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'NIM-ROLLBACK-01',
            'institution_name'         => $newInstName,
            'study_program'            => $newProgName,
            'internship_interest'      => 'Divisi Palsu Yang Pasti Tidak Ada', // Divisi tidak valid
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Alasan',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ];

        $res = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasErrors('internship_interest');

        // Master data tidak boleh tercipta atau tertinggal sebagai data yatim
        $this->assertFalse(City::where('name', $newCityName)->exists());
        $this->assertFalse(Institution::where('name', $newInstName)->exists());
        $this->assertFalse(StudyProgram::where('name', $newProgName)->exists());
        $this->assertFalse(IR::where('user_id', $user->id)->exists());
    }

    /**
     * 7. Dynamic attachment validation does not persist files prematurely and cleans up on failure.
     */
    public function test_dynamic_attachment_validation_does_not_persist_files_prematurely_and_cleans_up_on_failure(): void
    {
        Storage::fake('local');
        $service = app(FormBuilderService::class);

        $year = rand(2200, 2290);
        $schema = FormSchema::create([
            'version_code' => "v{$year}.1-draft",
            'year'         => $year,
            'title'        => 'File Schema',
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => false,
        ]);
        $service->addFieldToDraft($schema, [
            'field_key'   => 'berkas_tambahan',
            'field_type'  => 'file',
            'label'       => 'Berkas Tambahan',
            'is_required' => false,
        ]);
        $service->addFieldToDraft($schema, [
            'field_key'   => 'field_wajib_gagal',
            'field_type'  => 'text',
            'label'       => 'Field Wajib Gagal',
            'is_required' => true,
        ]);
        $published = $service->publishVersion($schema);

        $testFile = UploadedFile::fake()->create('custom_doc.pdf', 1024, 'application/pdf');

        // Validasi dengan field wajib yang kosong
        $result = $service->validateCustomAnswers($published, [
            'berkas_tambahan' => $testFile,
        ]);

        $this->assertFalse($result['is_valid']);
        $this->assertArrayHasKey('field_wajib_gagal', $result['errors']);

        // Pastikan tidak ada file yang disimpan ke storage privat saat fase validasi
        $filesInStorage = Storage::disk('local')->allFiles('private/custom_answers');
        $this->assertEmpty($filesInStorage);

        // Instance UploadedFile tetap berada di cleaned
        $this->assertInstanceOf(UploadedFile::class, $result['cleaned']['berkas_tambahan']);
    }

    /**
     * 8. Sidecar payload sanitizes private file paths to prevent internal path leakage.
     */
    public function test_sidecar_payload_sanitizes_private_file_paths(): void
    {
        $h = $this->createHierarchy();
        $user = $this->makeUser('pemagang');

        $reg = IR::create([
            'user_id'             => $user->id,
            'fullname'            => 'Sanitize Test Intern',
            'email'               => $user->email,
            'phone_number'        => '081255556666',
            'city_id'             => $h['city']->id,
            'institution_id'      => $h['inst']->id,
            'faculty_id'          => $h['fac']->id,
            'study_program_id'    => $h['prog']->id,
            'division_id'         => $h['div']->id,
            'internship_status'   => IR::STATUS_WAITING,
            'internship_type'     => 'Magang Mandiri',
            'student_id'          => 'SAN-01',
            'gender'              => 'Laki-laki',
            'current_status'      => 'Mahasiswa',
            'internship_reason'   => 'Alasan',
            'wfo_readiness'       => 'Siap',
            'schedule_shift_readiness' => 'Sanggup',
            'planned_duration'    => '3 Bulan',
        ]);

        // Simpan custom answer dengan path server privat
        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'berkas_portofolio_pdf',
            'answer_value'    => 'private/custom_answers/portofolio_rahasia.pdf',
            'answer_label'    => 'portofolio_rahasia.pdf',
        ]);

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'jawaban_teks_biasa',
            'answer_value'    => 'Nilai Teks Biasa',
            'answer_label'    => 'Nilai Teks Biasa',
        ]);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildSidecarPayload($reg);

        // Path direktori privat internal 'private/custom_answers/' harus tersanitasi menjadi nama berkas
        $this->assertEquals('portofolio_rahasia.pdf', $payload['answers']['berkas_portofolio_pdf']);
        $this->assertStringNotContainsString('private/custom_answers/', $payload['answers']['berkas_portofolio_pdf']);
        $this->assertEquals('Nilai Teks Biasa', $payload['answers']['jawaban_teks_biasa']);
    }
}
