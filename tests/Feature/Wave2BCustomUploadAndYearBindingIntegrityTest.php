<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\RegistrationCustomAnswer;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\RegistrationSpreadsheetMappingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class Wave2BCustomUploadAndYearBindingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected FormBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FormBuilderService::class);
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');

        Division::firstOrCreate(
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
            'fullname'                 => 'Rian Firmansyah',
            'student_id'               => '2026101401',
            'born_date'                => '2003-04-12',
            'gender'                   => 'Laki-laki',
            'phone_number'             => '081234567891',
            'email'                    => 'rian@example.com',
            'institution_name'         => 'Institut Teknologi Bandung',
            'faculty'                  => 'STEI',
            'study_program'            => 'Informatika',
            'current_city'             => 'Bandung',
            'internship_reason'        => 'Mendalami arsitektur sistem enterprise',
            'internship_interest'      => 'Programmer (Front End / Backend)',
            'start_date'               => '2026-03-01',
            'end_date'                 => '2026-06-01',
            'internship_type'          => 'campus',
            'current_status'           => 'Student',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'has_laptop'               => '1',
            'planned_duration'         => '3 bulan',
            'cv_ktp_portofolio_pdf'    => UploadedFile::fake()->create('berkas_syarat.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    // =========================================================================
    // FIX 1 — CUSTOM FILE ANSWER SPOOFING & REFERENCE OWNERSHIP
    // =========================================================================

    /**
     * 1. New registration rejects arbitrary string path for file field.
     */
    public function test_new_registration_rejects_arbitrary_string_path_for_file_field(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_sertifikat',
            'field_type'  => 'file',
            'label'       => 'Sertifikat Keahlian',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Client mencoba mengirim string path fisik tanpa mengunggah berkas UploadedFile
        $payload = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_sertifikat'   => 'private/custom_answers/fake_sertifikat.pdf',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasErrors(['custom_sertifikat']);
        $this->assertDatabaseMissing('registration_custom_answers', [
            'field_key' => 'custom_sertifikat',
        ]);
    }

    /**
     * 2. Rejects null byte, path traversal, and remote URL in file reference strings.
     */
    public function test_rejects_null_byte_path_traversal_and_remote_url_in_file_reference(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_portofolio_file',
            'field_type'  => 'file',
            'label'       => 'Berkas Portofolio',
            'is_required' => false,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        $maliciousStrings = [
            'private/custom_answers/file.pdf' . "\0" . '.jpg',
            '../../etc/passwd',
            '..\\..\\windows\\system32',
            'http://attacker.example.com/malicious.pdf',
            'https://storage.googleapis.com/bucket/doc.pdf',
            'file:///etc/hosts',
        ];

        foreach ($maliciousStrings as $malicious) {
            $payload = $this->validRegistrationPayload([
                'form_schema_version'    => $published->version_code,
                'custom_portofolio_file' => $malicious,
            ]);

            $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
            $res->assertSessionHasErrors(['custom_portofolio_file']);
        }
    }

    /**
     * 3. Rejects cross-registration file reference spoofing.
     */
    public function test_rejects_cross_registration_file_reference_spoofing(): void
    {
        $admin = $this->createAdmin();
        $victim = $this->createPemagang();
        $attacker = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_doc_rahasia',
            'field_type'  => 'file',
            'label'       => 'Dokumen Pribadi',
            'is_required' => false,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Korban mengunggah berkas sah
        $uploaded = UploadedFile::fake()->create('dokumen_korban.pdf', 200, 'application/pdf');
        $victimPayload = $this->validRegistrationPayload([
            'email'               => 'victim@example.com',
            'form_schema_version' => $published->version_code,
            'custom_doc_rahasia'  => $uploaded,
        ]);
        $this->actingAs($victim)->post(route('pemagang.registration.store'), $victimPayload);

        $victimAnswer = RegistrationCustomAnswer::where('field_key', 'custom_doc_rahasia')->first();
        $this->assertNotNull($victimAnswer);
        $victimFilePath = $victimAnswer->answer_value;
        $this->assertTrue(Storage::disk('local')->exists($victimFilePath));

        // Penyerang mencoba menyalin path berkas korban ke pendaftaran miliknya
        $attackerPayload = $this->validRegistrationPayload([
            'email'               => 'attacker@example.com',
            'form_schema_version' => $published->version_code,
            'custom_doc_rahasia'  => $victimFilePath,
        ]);

        $res = $this->actingAs($attacker)->post(route('pemagang.registration.store'), $attackerPayload);
        $res->assertSessionHasErrors(['custom_doc_rahasia']);

        // Pastikan tidak ada rekaman custom answer untuk penyerang dengan path korban
        $attackerReg = IR::where('user_id', $attacker->id)->first();
        $this->assertNull($attackerReg);
    }

    /**
     * 4. Retaining existing file when not re-uploaded succeeds seamlessly without error.
     */
    public function test_retaining_existing_file_when_not_reuploaded_succeeds_without_error(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_wajib_file',
            'field_type'  => 'file',
            'label'       => 'Surat Rekomendasi Wajib',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // 1. Submit awal dengan UploadedFile
        $uploaded = UploadedFile::fake()->create('rekomendasi.pdf', 300, 'application/pdf');
        $payloadInitial = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_wajib_file'   => $uploaded,
        ]);
        $resInitial = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadInitial);
        $resInitial->assertSessionHasNoErrors();

        $answer = RegistrationCustomAnswer::where('field_key', 'custom_wajib_file')->first();
        $this->assertNotNull($answer);
        $originalStoredPath = $answer->answer_value;
        $this->assertTrue(Storage::disk('local')->exists($originalStoredPath));

        // 2. Pemagang memperbarui profil tanpa mengunggah ulang file (input file bernilai null / tidak dikirim)
        $payloadUpdate = $this->validRegistrationPayload([
            'fullname'            => 'Rian Firmansyah Updated',
            'form_schema_version' => $published->version_code,
            // custom_wajib_file sengaja dihilangkan / tidak dikirim
        ]);
        unset($payloadUpdate['custom_wajib_file']);

        $resUpdate = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadUpdate);
        $resUpdate->assertSessionHasNoErrors();

        // 3. Pastikan file lama tetap dipertahankan dan path tidak berubah
        $freshAnswer = $answer->fresh();
        $this->assertEquals($originalStoredPath, $freshAnswer->answer_value);
        $this->assertTrue(Storage::disk('local')->exists($originalStoredPath));
    }

    /**
     * 5. Client submitting verified existing file reference matching own registration succeeds.
     */
    public function test_client_submitting_verified_existing_file_reference_matching_own_registration_succeeds(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_berkas_ktp',
            'field_type'  => 'file',
            'label'       => 'Berkas KTP Khusus',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Submit awal
        $uploaded = UploadedFile::fake()->create('ktp.png', 150, 'image/png');
        $payloadInitial = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_berkas_ktp'   => $uploaded,
        ]);
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadInitial);

        $answer = RegistrationCustomAnswer::where('field_key', 'custom_berkas_ktp')->first();
        $originalPath = $answer->answer_value;

        // Re-submit mengirim kembali path miliknya sendiri yang sah
        $payloadResubmit = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_berkas_ktp'   => $originalPath,
        ]);
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadResubmit);
        $res->assertSessionHasNoErrors();

        $this->assertEquals($originalPath, $answer->fresh()->answer_value);
    }

    /**
     * 6. File upload quota: individual <= 5MB and cumulative <= 20MB enforced.
     */
    public function test_file_upload_enforces_individual_max_size_and_cumulative_20mb_quota(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_file_1',
            'field_type'  => 'file',
            'label'       => 'File Satu',
            'is_required' => false,
        ]);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_file_2',
            'field_type'  => 'file',
            'label'       => 'File Dua',
            'is_required' => false,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // A. File individual melebihi 5MB (misal 5.5MB = 5632 KB)
        $oversizedFile = UploadedFile::fake()->create('oversized.pdf', 5632, 'application/pdf');
        $payloadA = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_file_1'       => $oversizedFile,
        ]);
        $resA = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadA);
        $resA->assertSessionHasErrors(['custom_file_1']);

        // B. Kumulatif melebihi 20MB (misal dua file masing-masing 4MB dan 18MB, total > 20MB)
        // Kita uji validasi service secara langsung untuk menguji kuota kumulatif
        $file4mb = UploadedFile::fake()->create('file4mb.pdf', 4 * 1024, 'application/pdf');
        $file18mb = UploadedFile::fake()->create('file18mb.pdf', 18 * 1024, 'application/pdf');

        $validationResult = $this->service->validateCustomAnswers($published, [
            'custom_file_1' => $file4mb,
            'custom_file_2' => $file18mb,
        ]);

        $this->assertFalse($validationResult['is_valid']);
        $this->assertArrayHasKey('total_upload_quota', $validationResult['errors']);
    }

    /**
     * 7. Allowed MIME types and extensions are strictly enforced.
     */
    public function test_file_upload_rejects_unallowed_extensions_and_mimes(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_document',
            'field_type'  => 'file',
            'label'       => 'Dokumen',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Upload file PHP / executable
        $badFile = UploadedFile::fake()->create('exploit.php', 10, 'text/x-php');
        $payload = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_document'     => $badFile,
        ]);
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasErrors(['custom_document']);
    }

    // =========================================================================
    // FIX 2 — OUTER TRANSACTION FILE CLEANUP
    // =========================================================================

    /**
     * 8. Outer transaction failure cleans up newly stored custom files from disk.
     */
    public function test_outer_transaction_failure_cleans_up_newly_stored_custom_files_from_disk(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_attachment_x',
            'field_type'  => 'file',
            'label'       => 'Berkas Khusus X',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Mencegat event saving pada IR untuk menyimulasikan kegagalan database setelah penyimpanan file
        IR::saving(function ($model) {
            if ($model->fullname === 'Simulate Rollback User') {
                throw new \RuntimeException('Simulated database error in outer transaction');
            }
        });

        $file = UploadedFile::fake()->create('dokumen_x.pdf', 150, 'application/pdf');
        $payload = $this->validRegistrationPayload([
            'fullname'            => 'Simulate Rollback User',
            'form_schema_version' => $published->version_code,
            'custom_attachment_x' => $file,
        ]);

        try {
            $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        } catch (\RuntimeException $e) {
            $this->assertEquals('Simulated database error in outer transaction', $e->getMessage());
        }

        // Verifikasi bahwa direktori storage bersih dari file yang baru disimpan
        $storedFiles = Storage::disk('local')->allFiles('private/custom_answers');
        $this->assertEmpty($storedFiles, 'Newly stored custom files must be cleaned up on transaction rollback');

        // Pastikan tidak ada data yang tersimpan di database
        $this->assertDatabaseMissing('internship_registrations', [
            'fullname' => 'Simulate Rollback User',
        ]);
        $this->assertDatabaseMissing('registration_custom_answers', [
            'field_key' => 'custom_attachment_x',
        ]);
    }

    /**
     * 9. Outer transaction failure does not delete pre-existing historical files.
     */
    public function test_outer_transaction_failure_does_not_delete_preexisting_historical_files(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_stable_file',
            'field_type'  => 'file',
            'label'       => 'File Stabil',
            'is_required' => false,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // 1. Submit awal yang berhasil
        $initialFile = UploadedFile::fake()->create('initial_stable.pdf', 100, 'application/pdf');
        $payloadInitial = $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_stable_file'  => $initialFile,
        ]);
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadInitial);

        $answer = RegistrationCustomAnswer::where('field_key', 'custom_stable_file')->first();
        $this->assertNotNull($answer);
        $historicalPath = $answer->answer_value;
        $this->assertTrue(Storage::disk('local')->exists($historicalPath));

        // 2. Pemagang mencoba pembaruan yang gagal pada transaksi luar
        IR::saving(function ($model) {
            if ($model->fullname === 'Update Failed User') {
                throw new \RuntimeException('Simulated failure during update');
            }
        });

        $newFile = UploadedFile::fake()->create('new_attempt.pdf', 120, 'application/pdf');
        $payloadUpdate = $this->validRegistrationPayload([
            'fullname'            => 'Update Failed User',
            'form_schema_version' => $published->version_code,
            'custom_stable_file'  => $newFile,
        ]);

        try {
            $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payloadUpdate);
        } catch (\RuntimeException $e) {
            $this->assertEquals('Simulated failure during update', $e->getMessage());
        }

        // 3. Pastikan berkas historis tetap utuh di disk lokal dan tidak terhapus!
        $this->assertTrue(Storage::disk('local')->exists($historicalPath), 'Historical file must be preserved after rollback');
        $this->assertEquals($historicalPath, $answer->fresh()->answer_value);
    }

    // =========================================================================
    // FIX 3 — YEAR-SPECIFIC PUBLIC REGISTRATION SCHEMA & BOUND VERSION
    // =========================================================================

    /**
     * 10. Registration October 2026 with start_date January 2027 uses schema 2027.
     */
    public function test_registration_october_2026_with_start_date_january_2027_uses_schema_2027(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-15 10:00:00');
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $schema2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'custom_future_plan',
            'field_type'  => 'text',
            'label'       => 'Rencana 2027',
            'is_required' => false,
        ]);
        $schema2027 = $this->service->publishVersion($draft2027, $admin);

        $payload = $this->validRegistrationPayload([
            'start_date'          => '2027-01-15',
            'form_schema_version' => $schema2027->version_code,
            'custom_future_plan'  => 'Target 2027',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertNotNull($reg->formSnapshot);
        $this->assertEquals($schema2027->id, $reg->formSnapshot->form_schema_id);
        $this->assertEquals(2027, (int)$reg->formSnapshot->schema->year);
        \Carbon\Carbon::setTestNow();
    }

    /**
     * 11. Registration December 2026 with start_date December 2026 uses schema 2026.
     */
    public function test_registration_december_2026_with_start_date_december_2026_uses_schema_2026(): void
    {
        \Carbon\Carbon::setTestNow('2026-12-01 10:00:00');
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'custom_notes_2026',
            'field_type'  => 'text',
            'label'       => 'Catatan 2026',
            'is_required' => false,
        ]);
        $schema2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $schema2027 = $this->service->publishVersion($draft2027, $admin);

        $payload = $this->validRegistrationPayload([
            'start_date'          => '2026-12-20',
            'form_schema_version' => $schema2026->version_code,
            'custom_notes_2026'   => 'Catatan Desember 2026',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertNotNull($reg->formSnapshot);
        $this->assertEquals($schema2026->id, $reg->formSnapshot->form_schema_id);
        $this->assertEquals(2026, (int)$reg->formSnapshot->schema->year);
        \Carbon\Carbon::setTestNow();
    }

    /**
     * 12. Registration November 2027 with start_date February 2028 uses schema 2028.
     */
    public function test_registration_november_2027_with_start_date_february_2028_uses_schema_2028(): void
    {
        \Carbon\Carbon::setTestNow('2027-11-10 10:00:00');
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $schema2027 = $this->service->publishVersion($draft2027, $admin);

        $draft2028 = $this->service->getOrCreateDraft(2028, $admin);
        $this->service->addFieldToDraft($draft2028, [
            'field_key'   => 'custom_field_2028',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Khusus 2028',
            'is_required' => false,
        ]);
        $schema2028 = $this->service->publishVersion($draft2028, $admin);

        $payload = $this->validRegistrationPayload([
            'start_date'          => '2028-02-01',
            'form_schema_version' => $schema2028->version_code,
            'custom_field_2028'   => 'Jawaban 2028',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertNotNull($reg->formSnapshot);
        $this->assertEquals($schema2028->id, $reg->formSnapshot->form_schema_id);
        $this->assertEquals(2028, (int)$reg->formSnapshot->schema->year);
        \Carbon\Carbon::setTestNow();
    }

    /**
     * 13. Cross-year form schema version spoofing is rejected.
     */
    public function test_cross_year_form_schema_version_spoofing_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $schema2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2028 = $this->service->getOrCreateDraft(2028, $admin);
        $schema2028 = $this->service->publishVersion($draft2028, $admin);

        // start_date 2026 tapi form_schema_version 2028
        $spoofedPayload = $this->validRegistrationPayload([
            'start_date'          => '2026-04-01',
            'form_schema_version' => $schema2028->version_code,
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $spoofedPayload);
        $res->assertSessionHasErrors(['form_version']);
    }

    /**
     * 14. Draft without start_date is not permanently bound to wrong year.
     */
    public function test_draft_without_start_date_is_not_permanently_bound_to_wrong_year(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->publishVersion($draft2026, $admin);

        // Simpan draft tanpa start_date
        $draftPayload = $this->validRegistrationPayload([
            'fullname'   => 'Calon Pemagang Draft',
            'start_date' => null,
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $draftPayload);
        $res->assertSessionHasNoErrors();

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertTrue((bool)$reg->is_draft);
        $this->assertNull($reg->start_date);
        $this->assertNull($reg->formSnapshot);
    }

    /**
     * 15. Changing start_date before submission safely reselects schema.
     */
    public function test_changing_start_date_before_submission_safely_reselects_schema(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'custom_2026_only',
            'field_type'  => 'text',
            'label'       => 'Khusus 2026',
            'is_required' => false,
        ]);
        $schema2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'custom_2027_only',
            'field_type'  => 'text',
            'label'       => 'Khusus 2027',
            'is_required' => false,
        ]);
        $schema2027 = $this->service->publishVersion($draft2027, $admin);

        // 1. Simpan draft dengan start_date 2026
        $draftPayload1 = $this->validRegistrationPayload([
            'fullname'            => 'Pendaftar Ganti Tahun',
            'start_date'          => '2026-05-01',
            'form_schema_version' => $schema2026->version_code,
            'custom_2026_only'    => 'Jawaban untuk 2026',
        ]);
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $draftPayload1);

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'custom_2026_only',
        ]);

        // 2. Ubah start_date ke 2027 pada draft sebelum final submission
        $draftPayload2 = $this->validRegistrationPayload([
            'fullname'            => 'Pendaftar Ganti Tahun',
            'start_date'          => '2027-06-01',
            'form_schema_version' => $schema2027->version_code,
            'custom_2027_only'    => 'Jawaban untuk 2027',
        ]);
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $draftPayload2);
        $res->assertSessionHasErrors(['start_date']);

        // Sesuai Fix 1: Jawaban field lama TIDAK DIHAPUS secara diam-diam dan perubahan ditolak fail-closed
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'custom_2026_only',
        ]);
    }

    /**
     * 16. Existing registration with historical snapshot remains strictly consistent.
     */
    public function test_existing_registration_with_historical_snapshot_remains_strictly_consistent(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'  => 'custom_field_v2026',
            'field_type' => 'text',
            'label'      => 'Field 2026',
        ]);
        $schema2026 = $this->service->publishVersion($draft2026, $admin);

        $payload = $this->validRegistrationPayload([
            'start_date'          => '2026-03-01',
            'form_schema_version' => $schema2026->version_code,
            'custom_field_v2026'  => 'Jawaban 2026',
        ]);
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg->formSnapshot);
        $this->assertEquals($schema2026->id, $reg->formSnapshot->form_schema_id);

        // Menerbitkan skema 2027
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $schema2027 = $this->service->publishVersion($draft2027, $admin);

        // Mencoba mengubah start_date melintasi batas tahun setelah pendaftaran disubmit (memiliki snapshot)
        $updatePayload = array_merge($payload, [
            'start_date' => '2027-04-01',
        ]);
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $updatePayload);
        $res->assertSessionHasErrors(['start_date']);

        // Snapshot historis tetap terikat ke skema 2026
        $this->assertEquals($schema2026->id, $reg->fresh()->formSnapshot->form_schema_id);
        $this->assertEquals(2026, (int)$reg->fresh()->formSnapshot->schema->year);
    }

    /**
     * 17. Calendar year change without start_date change does not change schema.
     */
    public function test_calendar_year_change_without_start_date_change_does_not_change_schema(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-01 10:00:00');
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $schema2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $schema2027 = $this->service->publishVersion($draft2027, $admin);

        // Pendaftaran dengan start_date di tahun 2026
        $payload = $this->validRegistrationPayload([
            'start_date'          => '2026-12-15',
            'form_schema_version' => $schema2026->version_code,
        ]);
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);

        $reg = IR::where('user_id', $pemagang->id)->first();

        // Kalender server berganti ke tahun 2027
        \Carbon\Carbon::setTestNow('2027-02-01 10:00:00');

        // Akses form pendaftaran oleh pemagang
        $res = $this->actingAs($pemagang)->get(route('pemagang.registration.form'));
        $res->assertOk();
        // Skema yang ditampilkan tetap skema 2026 dari snapshot/start_date, BUKAN 2027 dari kalender server
        $res->assertViewHas('activeSchema', fn($s) => $s->id === $schema2026->id);
        \Carbon\Carbon::setTestNow();
    }

    /**
     * 18. Missing published schema for target year produces safe error.
     */
    public function test_missing_published_schema_for_target_year_produces_safe_error(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Hanya skema 2026 yang ada
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->publishVersion($draft2026, $admin);

        // Pendaftar mencoba submit final dengan start_date di tahun 2029 (skema 2029 belum dipublikasikan)
        $payload = $this->validRegistrationPayload([
            'start_date' => '2029-03-01',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasErrors(['form_version']);
        $this->assertStringContainsString('2029', session('errors')->first('form_version'));

        // Pada halaman form (GET), request start_date 2029 menghasilkan activeSchema null dan alert informatif
        $resGet = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2029-03-01']));
        $resGet->assertOk();
        $resGet->assertViewHas('activeSchema', null);
    }

    /**
     * 19. Registration submission before admin acceptance does not trigger spreadsheet sync.
     */
    public function test_registration_submission_before_admin_acceptance_does_not_trigger_spreadsheet_sync(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $schema2026 = $this->service->publishVersion($draft2026, $admin);

        $payload = $this->validRegistrationPayload([
            'start_date'          => '2026-05-01',
            'form_schema_version' => $schema2026->version_code,
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertContains($reg->internship_status, [IR::STATUS_WAITING, IR::STATUS_NEW]);

        // Verifikasi service Spreadsheet: pendaftaran yang belum di-accept DILARANG disinkronisasi
        $spreadsheetService = app(RegistrationSpreadsheetMappingService::class);
        $this->assertFalse(
            $spreadsheetService->isEligibleForSync($reg),
            'Unaccepted registration must NEVER be eligible for spreadsheet sync'
        );

        // Tidak ada job sinkronisasi antrean yang dikirim saat submit pendaftaran oleh pemagang
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    // =========================================================================
    // FIX 4 — FORM FIELD VALIDATION_RULES ALLOWLIST & SERVER ENFORCEMENT
    // =========================================================================

    /**
     * 14. Admin creating draft field with illegal rule keys is rejected with 422.
     */
    public function test_admin_creating_draft_field_with_illegal_rule_keys_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $res = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'field_type'       => 'text',
            'label'            => 'Field Aturan Ilegal',
            'validation_rules' => json_encode(['unsupported_key' => 'malicious_value']),
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('tidak didukung', $res->json('message'));
    }

    /**
     * 15. Admin creating draft field with invalid ranges is rejected.
     */
    public function test_admin_creating_draft_field_with_invalid_range_is_rejected(): void
    {
        $admin = $this->createAdmin();

        // A. min_length > max_length
        $resA = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'field_type'       => 'text',
            'label'            => 'Field Panjang Tidak Logis',
            'validation_rules' => json_encode(['min_length' => 20, 'max_length' => 10]),
        ]);
        $resA->assertStatus(422);
        $this->assertStringContainsString('min_length', $resA->json('message'));

        // B. min_value > max_value
        $resB = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'field_type'       => 'number',
            'label'            => 'Field Angka Tidak Logis',
            'validation_rules' => json_encode(['min_value' => 100, 'max_value' => 50]),
        ]);
        $resB->assertStatus(422);
        $this->assertStringContainsString('min_value', $resB->json('message'));

        // C. min_date > max_date
        $resC = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'field_type'       => 'date',
            'label'            => 'Field Tanggal Tidak Logis',
            'validation_rules' => json_encode(['min_date' => '2026-12-31', 'max_date' => '2026-01-01']),
        ]);
        $resC->assertStatus(422);
        $this->assertStringContainsString('min_date', $resC->json('message'));

        // D. min_items > max_items
        $resD = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'field_type'       => 'checkbox',
            'label'            => 'Field Checkbox Tidak Logis',
            'validation_rules' => json_encode(['min_items' => 5, 'max_items' => 2]),
        ]);
        $resD->assertStatus(422);
        $this->assertStringContainsString('min_items', $resD->json('message'));

        // E. max_file_size_kb > 5120
        $resE = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'field_type'       => 'file',
            'label'            => 'Field Berkas Terlalu Besar',
            'validation_rules' => json_encode(['max_file_size_kb' => 10240]),
        ]);
        $resE->assertStatus(422);
        $this->assertStringContainsString('max_file_size_kb', $resE->json('message'));

        // F. allowed_extensions mengandung ekstensi terlarang
        $resF = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'field_type'       => 'file',
            'label'            => 'Field Ekstensi Terlarang',
            'validation_rules' => json_encode(['allowed_extensions' => ['exe', 'php']]),
        ]);
        $resF->assertStatus(422);
        $this->assertStringContainsString('tidak didukung', $resF->json('message'));
    }

    /**
     * 16. Server enforces min_length and max_length for text fields.
     */
    public function test_server_enforces_min_max_length_for_text(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'        => 'custom_ringkasan',
            'field_type'       => 'text',
            'label'            => 'Ringkasan Diri',
            'is_required'      => true,
            'validation_rules' => [
                'min_length' => 10,
                'max_length' => 25,
            ],
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Terlalu pendek (< 10 karakter)
        $resShort = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_ringkasan'    => 'Pendek',
        ]));
        $resShort->assertSessionHasErrors(['custom_ringkasan']);

        // Terlalu panjang (> 25 karakter)
        $resLong = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_ringkasan'    => 'Ini adalah kalimat yang sangat panjang melebihi 25 karakter.',
        ]));
        $resLong->assertSessionHasErrors(['custom_ringkasan']);

        // Sesuai batas (antara 10 dan 25 karakter)
        $resValid = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_ringkasan'    => 'Panjang Pas 16ch',
        ]));
        $resValid->assertSessionHasNoErrors();
    }

    /**
     * 17. Server enforces min_value and max_value for number, and treats zero as valid non-empty.
     */
    public function test_server_enforces_min_max_value_for_number_and_treats_zero_as_valid(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'        => 'custom_skor_tes',
            'field_type'       => 'number',
            'label'            => 'Skor Tes Masuk',
            'is_required'      => true,
            'validation_rules' => [
                'min_value' => 0,
                'max_value' => 100,
            ],
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Nilai negatif (< 0) -> DITOLAK
        $resNegative = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_skor_tes'     => -10,
        ]));
        $resNegative->assertSessionHasErrors(['custom_skor_tes']);

        // Nilai lebih dari 100 (> 100) -> DITOLAK
        $resTooHigh = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_skor_tes'     => 150,
        ]));
        $resTooHigh->assertSessionHasErrors(['custom_skor_tes']);

        // Nilai 0 (nol) HARUS DITERIMA sebagai angka valid, bukan dianggap kosong!
        $resZero = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_skor_tes'     => '0',
        ]));
        $resZero->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'custom_skor_tes',
            'answer_value' => '0',
        ]);
    }

    /**
     * 18. Server enforces min_date and max_date for date fields.
     */
    public function test_server_enforces_min_max_date_for_date(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'        => 'custom_tgl_wisuda',
            'field_type'       => 'date',
            'label'            => 'Perkiraan Tanggal Wisuda',
            'is_required'      => false,
            'validation_rules' => [
                'min_date' => '2026-06-01',
                'max_date' => '2026-12-31',
            ],
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Tanggal lebih awal dari min_date
        $resEarly = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_tgl_wisuda'   => '2026-05-15',
        ]));
        $resEarly->assertSessionHasErrors(['custom_tgl_wisuda']);

        // Tanggal lebih lambat dari max_date
        $resLate = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_tgl_wisuda'   => '2027-01-10',
        ]));
        $resLate->assertSessionHasErrors(['custom_tgl_wisuda']);

        // Tanggal valid
        $resValid = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_tgl_wisuda'   => '2026-08-20',
        ]));
        $resValid->assertSessionHasNoErrors();
    }

    /**
     * 19. Server enforces min_items and max_items for checkbox fields.
     */
    public function test_server_enforces_min_max_items_for_checkbox(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'        => 'custom_minat_teknologi',
            'field_type'       => 'checkbox',
            'label'            => 'Minat Teknologi',
            'is_required'      => true,
            'options'          => [
                ['value' => 'laravel', 'label' => 'Laravel'],
                ['value' => 'vue', 'label' => 'Vue.js'],
                ['value' => 'react', 'label' => 'React'],
                ['value' => 'flutter', 'label' => 'Flutter'],
            ],
            'validation_rules' => [
                'min_items' => 2,
                'max_items' => 3,
            ],
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Hanya memilih 1 item (< min_items 2)
        $resTooFew = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version'    => $published->version_code,
            'custom_minat_teknologi' => ['laravel'],
        ]));
        $resTooFew->assertSessionHasErrors(['custom_minat_teknologi']);

        // Memilih 4 item (> max_items 3)
        $resTooMany = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version'    => $published->version_code,
            'custom_minat_teknologi' => ['laravel', 'vue', 'react', 'flutter'],
        ]));
        $resTooMany->assertSessionHasErrors(['custom_minat_teknologi']);

        // Memilih 2 item (valid)
        $resValid = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version'    => $published->version_code,
            'custom_minat_teknologi' => ['laravel', 'vue'],
        ]));
        $resValid->assertSessionHasNoErrors();
    }

    /**
     * 20. Server enforces custom allowed_extensions and max_file_size_kb for file fields.
     */
    public function test_server_enforces_custom_allowed_extensions_and_max_file_size_kb_for_file(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'        => 'custom_strict_pdf',
            'field_type'       => 'file',
            'label'            => 'Hanya Dokumen PDF Maks 1MB',
            'is_required'      => true,
            'validation_rules' => [
                'allowed_extensions' => ['pdf'],
                'max_file_size_kb'   => 1024, // 1MB
            ],
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        // Upload berkas PNG (ekstensi tidak diizinkan pada field ini meskipun gambar didukung global)
        $wrongExtFile = UploadedFile::fake()->create('foto.png', 500, 'image/png');
        $resWrongExt = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_strict_pdf'   => $wrongExtFile,
        ]));
        $resWrongExt->assertSessionHasErrors(['custom_strict_pdf']);

        // Upload berkas PDF tetapi ukuran melebihi 1024KB (1.5MB)
        $tooBigFile = UploadedFile::fake()->create('besar.pdf', 1536, 'application/pdf');
        $resTooBig = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_strict_pdf'   => $tooBigFile,
        ]));
        $resTooBig->assertSessionHasErrors(['custom_strict_pdf']);

        // Upload berkas PDF valid (500KB)
        $validFile = UploadedFile::fake()->create('valid.pdf', 500, 'application/pdf');
        $resValid = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'form_schema_version' => $published->version_code,
            'custom_strict_pdf'   => $validFile,
        ]));
        $resValid->assertSessionHasNoErrors();
    }

    /**
     * 21. String '0' on required text field is treated as non-empty.
     */
    public function test_string_zero_is_treated_as_non_empty_on_required_text_field(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_kode_pos_digit',
            'field_type'  => 'text',
            'label'       => 'Digit Tambahan',
            'is_required' => true,
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        $payload = $this->validRegistrationPayload([
            'form_schema_version'    => $published->version_code,
            'custom_kode_pos_digit' => '0',
        ]);

        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $payload);
        $res->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'custom_kode_pos_digit',
            'answer_value' => '0',
        ]);
    }

    /**
     * 22. GATE 1 — GET registration form switches schema, custom fields, and hidden version by start_date query.
     */
    public function test_gate1_get_registration_form_switches_schema_and_version_by_start_date_query(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 1. Skema 2026
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'pertanyaan_khusus_2026',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Unik 2026',
            'is_required' => false,
        ]);
        $published2026 = $this->service->publishVersion($draft2026, $admin);

        // 2. Skema 2027
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'pertanyaan_khusus_2027',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Unik 2027',
            'is_required' => false,
        ]);
        $published2027 = $this->service->publishVersion($draft2027, $admin);

        // A. Buka form tanpa start_date: menampilkan skema 2026 (kalender preview)
        $resNoDate = $this->actingAs($pemagang)->get(route('pemagang.registration.form'));
        $resNoDate->assertOk();
        $resNoDate->assertSee($published2026->version_code);
        $resNoDate->assertSee('Pertanyaan Unik 2026');
        $resNoDate->assertDontSee('Pertanyaan Unik 2027');

        // B. Buka form dengan start_date 2027: menampilkan skema 2027 secara dinamis
        $res2027 = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-03-15']));
        $res2027->assertOk();
        $res2027->assertSee($published2027->version_code);
        $res2027->assertSee('Pertanyaan Unik 2027');
        $res2027->assertDontSee('Pertanyaan Unik 2026');

        // C. Buka form dengan start_date 2028 (skema belum terbit): ramah & tidak menampilkan pertanyaan salah
        $res2028 = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2028-02-01']));
        $res2028->assertOk();
        $res2028->assertSee('Formulir pendaftaran untuk periode magang tahun 2028 belum dibuka atau belum dipublikasikan oleh Admin.');
        $res2028->assertDontSee('Pertanyaan Unik 2026');
        $res2028->assertDontSee('Pertanyaan Unik 2027');
    }

    /**
     * 23. FIX 1 — Cross-year change with existing incompatible answers rejects change and preserves all data.
     */
    public function test_fix1_cross_year_change_with_incompatible_answers_rejects_and_preserves_all_data(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 1. Skema 2026 memiliki field khusus 2026
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'pertanyaan_hanya_2026',
            'field_type'  => 'text',
            'label'       => 'Hanya 2026',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // 2. Skema 2027 tidak memiliki pertanyaan_hanya_2026
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'pertanyaan_baru_2027',
            'field_type'  => 'text',
            'label'       => 'Hanya 2027',
            'is_required' => false,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // 3. Simpan draft pada 2026 dengan isian pertanyaan_hanya_2026
        $resDraft1 = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'            => '2026-11-01',
            'form_schema_version'   => $pub2026->version_code,
            'pertanyaan_hanya_2026' => 'Nilai Lama 2026 Tetap Terjaga',
        ]));
        $resDraft1->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'pertanyaan_hanya_2026',
            'answer_value' => 'Nilai Lama 2026 Tetap Terjaga',
        ]);

        // 4. Upaya mengubah start_date ke 2027 HARUS DITOLAK fail-closed karena isian lama tidak ada pada skema 2027
        $resDraft2 = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-02-15',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $resDraft2->assertSessionHasErrors(['start_date']);

        // 5. Bukti Preservasi Data Mutlak:
        // A. Jawaban 2026 TIDAK DIHAPUS (Zero Silent Deletion)
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'pertanyaan_hanya_2026',
            'answer_value' => 'Nilai Lama 2026 Tetap Terjaga',
        ]);
        // B. start_date draft tetap berada pada tanggal lama (2026)
        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('2026-11-01', substr((string) $reg->start_date, 0, 10));
    }

    /**
     * 24. FIX 1 — Identical field_key but different semantics/options is not silently reused and rejects change.
     */
    public function test_fix1_identical_field_key_with_different_semantics_is_not_silently_reused(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 2026: select dengan opsi ['frontend', 'backend']
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'spesialisasi_pilihan',
            'field_type'  => 'select',
            'label'       => 'Pilihan Spesialisasi',
            'options'     => [
                ['value' => 'frontend', 'label' => 'Front End'],
                ['value' => 'backend', 'label' => 'Back End'],
            ],
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // 2027: skema memiliki opsi yang berbeda secara semantik
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        \App\Models\FormField::create([
            'form_schema_id'   => $draft2027->id,
            'field_key'        => 'spesialisasi_pilihan',
            'field_type'       => 'select',
            'label'            => 'Pilihan Spesialisasi 2027',
            'options'          => [
                ['value' => 'mobile', 'label' => 'Mobile Developer'],
                ['value' => 'ai', 'label' => 'AI Engineer'],
            ],
            'is_required'      => false,
            'is_active'        => true,
            'is_archived'      => false,
            'sort_order'       => 10,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draft 2026 dengan memilih 'frontend'
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'            => '2026-10-15',
            'form_schema_version'   => $pub2026->version_code,
            'spesialisasi_pilihan'  => 'frontend',
        ]));

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'spesialisasi_pilihan',
            'answer_value' => 'frontend',
        ]);

        // Coba ganti start_date ke 2027: nilai 'frontend' tidak valid untuk opsi skema 2027
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-03-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);

        // Data lama tetap utuh
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'spesialisasi_pilihan',
            'answer_value' => 'frontend',
        ]);
    }

    /**
     * 25. FIX 1 — Existing custom file references and physical files remain valid when year switch is rejected.
     */
    public function test_fix1_existing_file_references_remain_valid(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'dokumen_khusus_2026',
            'field_type'  => 'file',
            'label'       => 'Dokumen 2026',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Upload berkas custom pada draft 2026
        $file = UploadedFile::fake()->create('sertifikat_2026.pdf', 300, 'application/pdf');
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'           => '2026-11-10',
            'form_schema_version'  => $pub2026->version_code,
            'dokumen_khusus_2026'  => $file,
        ]));

        $answer = RegistrationCustomAnswer::where('field_key', 'dokumen_khusus_2026')->first();
        $this->assertNotNull($answer);
        $storedFilePath = $answer->answer_value;
        Storage::disk('local')->assertExists($storedFilePath);

        // Upaya ubah ke 2027 (incompatible field)
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-04-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);

        // Seluruh jawaban database dan berkas fisik TETAP TERJAGA UTUH
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'dokumen_khusus_2026',
            'answer_value' => $storedFilePath,
        ]);
        Storage::disk('local')->assertExists($storedFilePath);
    }

    /**
     * 26. FIX 1 — Cross-year change without custom answers remains completely unrestricted.
     */
    public function test_fix1_cross_year_change_without_custom_answers_remains_possible(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draft 2026 tanpa jawaban custom apapun
        $resDraft1 = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-10-15',
            'form_schema_version' => $pub2026->version_code,
        ]));
        $resDraft1->assertSessionHasNoErrors();

        // Ganti start_date ke 2027: berhasil karena tidak ada custom data yang terancam hilang
        $resDraft2 = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-02-20',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $resDraft2->assertSessionHasNoErrors();

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertEquals('2027-02-20', substr((string) $reg->start_date, 0, 10));
    }

    /**
     * 27. FIX 1 & FIX 3 — Cross-year change with fully compatible answers succeeds and flashes notice after commit.
     */
    public function test_fix1_and_fix3_compatible_cross_year_change_succeeds_and_flashes_after_commit(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Field bersama kompatibel (tipe sama, aturan sama)
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'pertanyaan_bersama',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Bersama',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'pertanyaan_bersama',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Bersama',
            'is_required' => false,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draft 2026
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-11-01',
            'form_schema_version' => $pub2026->version_code,
            'pertanyaan_bersama'  => 'Jawaban Kompatibel Tetap Aman',
        ]));

        // Ganti start_date ke 2027: seluruh jawaban kompatibel, berhasil disimpan
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-03-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasNoErrors();
        $res->assertSessionHas('schema_adjusted');

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'pertanyaan_bersama',
            'answer_value' => 'Jawaban Kompatibel Tetap Aman',
        ]);
    }

    /**
     * 28. FIX 3 — Failed transaction does not show false schema_adjusted flash and preserves all answers.
     */
    public function test_fix3_failed_transaction_does_not_show_false_schema_adjusted_flash(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'field_asal_2026',
            'field_type'  => 'text',
            'label'       => 'Asal 2026',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'field_asal_2026',
            'field_type'  => 'text',
            'label'       => 'Asal 2026',
            'is_required' => false,
        ]);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'field_wajib_2027',
            'field_type'  => 'text',
            'label'       => 'Wajib 2027',
            'is_required' => true,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draft 2026
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-10-20',
            'form_schema_version' => $pub2026->version_code,
            'field_asal_2026'     => 'Jawaban Asal 100% Aman',
        ]));

        // Kirim submit resmi untuk 2027 namun field_wajib_2027 kosong (gagal validasi sebelum transaksi)
        $resFailed = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'start_date'          => '2027-03-01',
            'form_schema_version' => $pub2027->version_code,
            'field_wajib_2027'    => '',
        ]));
        $resFailed->assertSessionHasErrors(['field_wajib_2027']);
        $resFailed->assertSessionMissing('schema_adjusted');

        // Jawaban field_asal_2026 TIDAK HILANG
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'field_asal_2026',
            'answer_value' => 'Jawaban Asal 100% Aman',
        ]);
    }

    /**
     * 29. FIX 2 — Client storage privacy: Blade view renders strict privacy isolation and clean whitelist.
     */
    public function test_fix2_client_storage_privacy_and_isolation(): void
    {
        $pemagang = $this->createPemagang();

        $res = $this->actingAs($pemagang)->get(route('pemagang.registration.form'));
        $res->assertOk();

        // 1. Terdapat penjelasan bahwa data identitas disimpan secara teks biasa (tidak terenkripsi)
        $res->assertSee('secara teks biasa (tidak terenkripsi) di perangkat ini khusus untuk akun Anda');
        $res->assertSee('Peringatan Perangkat Bersama');

        // 2. Mekanisme penghapusan draft browser tersedia
        $res->assertSee('Hapus Draft Browser');
        $res->assertSee('btn-clear-local');

        // 3. Menggunakan sessionStorage transien untuk transisi tahun dan per-user isolation
        $res->assertSee('sertif_year_transient_' . $pemagang->id);
        $res->assertSee('sertif_manual_draft_' . $pemagang->id);
        $res->assertSee('sessionStorage.removeItem');

        // 4. Whitelist strictly excludes tokens, files, and versions
        $res->assertSee('SAFE_CORE_FIELD_NAMES');
    }

    /**
     * 30. ISSUE 1 & 2 — Original schema unknown: Cross-year change blocked fail-closed when original year schema is missing.
     */
    public function test_original_schema_unknown_blocked(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 2026: Terbitkan skema dan simpan draft dengan jawaban kustom
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'pertanyaan_khusus',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Khusus',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draft untuk 2026
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-10-15',
            'form_schema_version' => $pub2026->version_code,
            'pertanyaan_khusus'   => 'Jawaban Asal 2026',
        ]));

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'pertanyaan_khusus',
            'answer_value' => 'Jawaban Asal 2026',
        ]);

        // Simulasikan skema asal 2026 hilang atau tidak aktif (misal dihapus atau status diubah)
        $pub2026->update(['is_active' => false, 'status' => FormSchema::STATUS_ARCHIVED]);

        // Upaya ubah tanggal ke 2027: skema aktif asal 2026 tidak ditemukan -> FAIL-CLOSED
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-04-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);

        // Data pendaftar lama 100% terjaga
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'pertanyaan_khusus',
            'answer_value' => 'Jawaban Asal 2026',
        ]);
    }

    /**
     * 31. ISSUE 1 — Old form_schema_id missing: Registration with invalid snapshot schema is blocked.
     */
    public function test_old_form_schema_id_missing_blocked(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // Submit registration final untuk 2026
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'start_date'          => '2026-11-01',
            'form_schema_version' => $pub2026->version_code,
        ]));

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertNotNull($reg->formSnapshot);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Rusak pointer form_schema_id pada snapshot via direct query (simulasikan missing/corrupt form_schema_id)
        \Illuminate\Support\Facades\DB::table('registration_form_snapshots')
            ->where('id', $reg->formSnapshot->id)
            ->update(['form_schema_id' => null]);

        // Coba perbarui pendaftaran: terblokir fail-closed
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'start_date'          => '2027-02-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);
    }

    /**
     * 32. ISSUE 2 — Same field_key, different meaning/label: Blocked fail-closed.
     */
    public function test_same_field_key_different_meaning_blocked(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 2026: field 'catatan' dengan label 'Pengalaman Organisasi'
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'catatan_pendaftar',
            'field_type'  => 'text',
            'label'       => 'Pengalaman Organisasi Kampus',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // 2027: field 'catatan' dengan tipe sama text tapi makna/label berbeda 'Riwayat Penyakit Khusus'
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        \App\Models\FormField::create([
            'form_schema_id'   => $draft2027->id,
            'field_key'        => 'catatan_pendaftar',
            'field_type'       => 'text',
            'label'            => 'Riwayat Penyakit Khusus',
            'is_required'      => false,
            'is_active'        => true,
            'is_archived'      => false,
            'sort_order'       => 10,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draf 2026 dengan jawaban
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-10-15',
            'form_schema_version' => $pub2026->version_code,
            'catatan_pendaftar'   => 'Ketua Himpunan Mahasiswa',
        ]));

        // Upaya ubah tanggal ke 2027: makna pertanyaan berbeda -> DITOLAK fail-closed
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-04-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);

        // Data tidak hilang
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'catatan_pendaftar',
            'answer_value' => 'Ketua Himpunan Mahasiswa',
        ]);
    }

    /**
     * 33. ISSUE 2 — Same key/type, stricter validation in target schema: Blocked fail-closed.
     */
    public function test_same_key_and_type_stricter_validation_handled(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 2026: min_length 3
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'        => 'motivasi_magang',
            'field_type'       => 'text',
            'label'            => 'Motivasi Magang',
            'validation_rules' => ['min_length' => 3],
            'is_required'      => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // 2027: min_length diperketat menjadi 50
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        \App\Models\FormField::create([
            'form_schema_id'   => $draft2027->id,
            'field_key'        => 'motivasi_magang',
            'field_type'       => 'text',
            'label'            => 'Motivasi Magang',
            'validation_rules' => ['min_length' => 50],
            'is_required'      => false,
            'is_active'        => true,
            'is_archived'      => false,
            'sort_order'       => 10,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draft 2026 dengan jawaban pendek (panjang 13 karakter)
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-10-15',
            'form_schema_version' => $pub2026->version_code,
            'motivasi_magang'     => 'Ingin belajar',
        ]));

        // Upaya ubah tanggal ke 2027: jawaban 13 karakter melanggar min_length 50 di 2027 -> DITOLAK
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-04-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'motivasi_magang',
            'answer_value' => 'Ingin belajar',
        ]);
    }

    /**
     * 34. ISSUE 2 — Missing original start_date with existing custom answers: Blocked fail-closed.
     */
    public function test_missing_original_start_date_with_existing_answers_blocked(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Buat registrasi draf yang TIDAK memiliki start_date via draft submission
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => null,
            'form_schema_version' => null,
        ]));

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertNull($reg->start_date);

        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'data_misterius',
            'answer_value'    => 'Nilai Tanpa Asal Tahun',
        ]);

        // Coba kirim post draft dengan mengisi start_date 2027: ditolak karena skema asal tidak dapat diverifikasi
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-06-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);

        // Data tetap utuh
        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'data_misterius',
            'answer_value'    => 'Nilai Tanpa Asal Tahun',
        ]);
    }

    /**
     * 35. ISSUE 2 — Valid identical schema semantics allows clean year migration without data loss.
     */
    public function test_valid_identical_schema_semantics_allowed(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 2026 dan 2027 memiliki field identik: key, label, tipe, dan opsi sama
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'bidang_minat',
            'field_type'  => 'text',
            'label'       => 'Bidang Minat Khusus',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'bidang_minat',
            'field_type'  => 'text',
            'label'       => 'Bidang Minat Khusus',
            'is_required' => false,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draf 2026
        $resDraft1 = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-10-15',
            'form_schema_version' => $pub2026->version_code,
            'bidang_minat'        => 'Kecerdasan Buatan',
        ]));
        $resDraft1->assertSessionHasNoErrors();

        // Ubah start_date ke 2027: seluruh semantik identik -> BERHASIL
        $resDraft2 = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2027-03-01',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $resDraft2->assertSessionHasNoErrors();
        $resDraft2->assertSessionHas('schema_adjusted');

        // Jawaban tidak dihapus dan start_date terbarui
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'bidang_minat',
            'answer_value' => 'Kecerdasan Buatan',
        ]);
        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertEquals('2027-03-01', substr((string) $reg->start_date, 0, 10));
    }

    /**
     * 36. ISSUE 2 — Transaction failure preserves all answers and file references.
     */
    public function test_transaction_failure_preserves_all_answers_and_file_references(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'dokumen_portofolio',
            'field_type'  => 'file',
            'label'       => 'Dokumen Portofolio',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // Simpan draf awal dengan berkas custom
        $file = UploadedFile::fake()->create('portofolio.pdf', 300, 'application/pdf');
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'           => '2026-11-01',
            'form_schema_version'  => $pub2026->version_code,
            'dokumen_portofolio'   => $file,
        ]));

        $ans = RegistrationCustomAnswer::where('field_key', 'dokumen_portofolio')->first();
        $this->assertNotNull($ans);
        $filePath = $ans->answer_value;
        Storage::disk('local')->assertExists($filePath);

        // Simulasikan kegagalan database saat transaksi penyimpanan berikutnya
        \App\Models\InternshipRegistration::saving(function ($model) {
            static $calls = 0;
            $calls++;
            if ($calls >= 2) {
                throw new \RuntimeException('SIMULATED_DB_TRANSACTION_FAILURE');
            }
        });

        try {
            $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
                'start_date'          => '2026-11-01',
                'form_schema_version' => $pub2026->version_code,
            ]));
        } catch (\RuntimeException $e) {
            $this->assertEquals('SIMULATED_DB_TRANSACTION_FAILURE', $e->getMessage());
        }

        // Jawaban database dan berkas fisik 100% terjaga utuh
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'dokumen_portofolio',
            'answer_value' => $filePath,
        ]);
        Storage::disk('local')->assertExists($filePath);
    }

    /**
     * 37. CRITICAL SCENARIO — FormSchema 2027 v1 used for draft, then v2 published; cross-year change blocks fail-closed without assuming v2.
     */
    public function test_multiversion_origin_year_draft_cannot_assume_v2_as_origin_of_v1_and_blocks_fail_closed(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 1. Publikasikan 2027 v1
        $draft2027v1 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027v1, [
            'field_key'   => 'pertanyaan_v1_saja',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Khusus Versi 1',
            'is_required' => false,
        ]);
        $pub2027v1 = $this->service->publishVersion($draft2027v1, $admin);

        // 2. Simpan draft pendaftar pada tahun 2027 dengan isian pertanyaan_v1_saja
        $resDraft1 = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'            => '2027-05-10',
            'form_schema_version'   => $pub2027v1->version_code,
            'pertanyaan_v1_saja'    => 'Jawaban Asal Versi 1',
        ]));
        $resDraft1->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'pertanyaan_v1_saja',
            'answer_value' => 'Jawaban Asal Versi 1',
        ]);

        // 3. Admin menerbitkan 2027 v2 (v1 menjadi status 'archived')
        $draft2027v2 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027v2 = $this->service->publishVersion($draft2027v2, $admin);

        $this->assertEquals(FormSchema::STATUS_ARCHIVED, $pub2027v1->fresh()->status);
        $this->assertEquals(FormSchema::STATUS_PUBLISHED, $pub2027v2->fresh()->status);

        // 4. Publikasikan skema tahun 2026 sebagai target perubahan tahun
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // 5. Pendaftar mencoba mengubah start_date dari 2027 menuju 2026
        // Aplikasi DILARANG menganggap v2 sebagai skema asal v1 tanpa bukti.
        // Karena terdapat dua versi skema untuk 2027 dan draf belum memiliki snapshot resmi,
        // perubahan tahun WAJIB DITOLAK FAIL-CLOSED!
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-10-15',
            'form_schema_version' => $pub2026->version_code,
        ]));
        $res->assertSessionHasErrors(['start_date']);

        // 6. Data jawaban draf tetap 100% UTUH dan tidak hilang
        $this->assertDatabaseHas('registration_custom_answers', [
            'field_key'    => 'pertanyaan_v1_saja',
            'answer_value' => 'Jawaban Asal Versi 1',
        ]);
    }

    /**
     * 38. CRITICAL SCENARIO — Draft in same year does not silently swap validation contract from v1 to v2.
     */
    public function test_draft_in_same_year_does_not_silently_swap_validation_contract_from_v1_to_v2(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // 1. Publikasikan 2027 v1 dengan batas min_length = 3
        $draft2027v1 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027v1, [
            'field_key'        => 'pertanyaan_kontrak',
            'field_type'       => 'text',
            'label'            => 'Kontrak Pertanyaan',
            'validation_rules' => ['min_length' => 3],
            'is_required'      => true,
        ]);
        $pub2027v1 = $this->service->publishVersion($draft2027v1, $admin);

        // 2. Admin menerbitkan 2027 v2 dengan field_wajib_baru_v2 yang WAJIB diisi
        $draft2027v2 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027v2, [
            'field_key'   => 'field_wajib_baru_v2',
            'field_type'  => 'text',
            'label'       => 'Wajib Baru Pada Versi 2',
            'is_required' => true,
        ]);
        $pub2027v2 = $this->service->publishVersion($draft2027v2, $admin);

        // 3. Pendaftar mengirim submit dengan form_schema_version v2027.1 yang masih dalam grace period
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'start_date'            => '2027-04-01',
            'form_schema_version'   => $pub2027v1->version_code,
            'pertanyaan_kontrak'    => 'Jawaban Sah Sesuai V1',
            // Perhatikan: field_wajib_baru_v2 TIDAK DIKIRIM (karena pendaftar mengisi form v1)
        ]));
        $res->assertSessionHasNoErrors();

        // Snapshot yang dibekukan membuktikan bahwa kontrak terikat adalah v1, BUKAN v2!
        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertNotNull($reg->formSnapshot);
        $this->assertEquals($pub2027v1->version_code, $reg->formSnapshot->form_schema_version);
        $this->assertEquals($pub2027v1->id, $reg->formSnapshot->form_schema_id);
    }

    /**
     * Helper asersi terarah untuk memverifikasi atribut value yang terikat secara presisi
     * pada elemen HTML <input name="start_date"> tanpa false positive dari elemen lain.
     */
    protected function assertStartDateInputValue(\Illuminate\Testing\TestResponse $response, ?string $expectedValue): void
    {
        $html = $response->getContent();
        $matched = preg_match('/<input[^>]+name="start_date"[^>]*>/i', $html, $matches);
        $this->assertTrue((bool)$matched, 'Input name="start_date" tidak ditemukan dalam response HTML.');

        $hasVal = preg_match('/value="([^"]*)"/i', $matches[0], $valMatch);
        $actualValue = $hasVal ? $valMatch[1] : '';

        if ($expectedValue === null || $expectedValue === '') {
            $this->assertSame('', $actualValue, "Input name=\"start_date\" seharusnya kosong, namun ditemukan: {$actualValue}");
        } else {
            $this->assertTrue((bool)$hasVal, "Input name=\"start_date\" tidak memiliki atribut value=\"{$expectedValue}\"");
            $this->assertSame($expectedValue, $actualValue, "Atribut value input name=\"start_date\" tidak sesuai: ekspektasi {$expectedValue}, aktual {$actualValue}");
        }
    }

    /**
     * 39. ISSUE 1 & 3 — GET registration form with valid query date renders consistent HTML input, schema, and version.
     */
    public function test_get_form_with_valid_query_date_renders_consistent_html_input_and_schema_version(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Terbitkan skema tahun 2027 dengan custom field khusus
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'pertanyaan_eksklusif_2027',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Eksklusif 2027',
            'is_required' => false,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // GET dengan parameter ?start_date=2027-03-15
        $res = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-03-15']));
        $res->assertOk();

        // 1. Input date name="start_date" wajib terisi value="2027-03-15" (terikat presisi pada atribut tag input)
        $this->assertStartDateInputValue($res, '2027-03-15');

        // 2. Hidden form_schema_version dan form_schema_id sesuai dengan skema 2027
        $res->assertSee('value="' . $pub2027->version_code . '"', false);
        $res->assertSee('value="' . $pub2027->id . '"', false);

        // 3. Pertanyaan custom skema 2027 dirender
        $res->assertSee('pertanyaan_eksklusif_2027');
        $res->assertSee('Pertanyaan Eksklusif 2027');
    }

    /**
     * 40. ISSUE 1 & 3 — Existing draft 2026 previews 2027 without displaying 2026 as active input value.
     */
    public function test_existing_draft_2026_previews_2027_without_displaying_2026_as_active_input_value(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Terbitkan skema 2026 dan 2027
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'pertanyaan_2027',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan 2027',
            'is_required' => false,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Simpan draf 2026
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2026-06-15',
            'form_schema_version' => $pub2026->version_code,
        ]));

        $regBefore = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($regBefore);
        $this->assertEquals('2026-06-15', substr((string)$regBefore->start_date, 0, 10));

        // GET preview 2027: ?start_date=2027-03-15
        $res = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-03-15']));
        $res->assertOk();

        // 1. Input date menampilkan value="2027-03-15" (terikat presisi pada input name="start_date")
        $this->assertStartDateInputValue($res, '2027-03-15');

        // 2. Input date TIDAK menampilkan value="2026-06-15"
        $res->assertDontSee('value="2026-06-15"', false);

        // 3. Hidden version dan ID terikat ke skema 2027
        $res->assertSee('value="' . $pub2027->version_code . '"', false);
        $res->assertSee('value="' . $pub2027->id . '"', false);

        // 4. Pertanyaan custom 2027 dirender
        $res->assertSee('pertanyaan_2027');

        // 5. Database draf TIDAK bermutasi karena request GET
        $regAfter = $regBefore->fresh();
        $this->assertEquals('2026-06-15', substr((string)$regAfter->start_date, 0, 10));
    }

    /**
     * 41. ISSUE 3 — POST submission enforces consistent date and schema.
     */
    public function test_post_submission_enforces_consistent_date_and_schema(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Terbitkan skema 2027
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // 1. POST dengan start_date 2027-03-15 dan form_schema_version 2027 sukses
        $res = $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'start_date'          => '2027-03-15',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $res->assertSessionHasNoErrors();

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('2027-03-15', substr((string)$reg->start_date, 0, 10));
        $this->assertNotNull($reg->formSnapshot);
        $this->assertEquals($pub2027->version_code, $reg->formSnapshot->form_schema_version);
        $this->assertEquals($pub2027->id, $reg->formSnapshot->form_schema_id);

        // 2. Snapshot membekukan tanggal dan versi: upaya ubah ke tahun lain ditolak
        $resChange = $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'          => '2028-02-15',
            'form_schema_version' => $pub2027->version_code,
        ]));
        $resChange->assertSessionHasErrors(['start_date']);
    }

    /**
     * 42. ISSUE 3 — GET request causes zero mutations to saved start_date, snapshot, answers, or files.
     */
    public function test_get_request_causes_zero_mutations_to_saved_start_date_snapshot_answers_or_files(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2026, [
            'field_key'   => 'pertanyaan_draf_aman',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Draf Aman',
            'is_required' => false,
        ]);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Buat draf dengan custom answers
        $this->actingAs($pemagang)->post(route('pemagang.registration.draft'), $this->validRegistrationPayload([
            'start_date'           => '2026-08-20',
            'form_schema_version'  => $pub2026->version_code,
            'pertanyaan_draf_aman' => 'Jawaban Asli Tidak Boleh Berubah',
        ]));

        $regBefore = IR::where('user_id', $pemagang->id)->first();
        $regCountBefore = IR::count();
        $ansCountBefore = \App\Models\RegistrationCustomAnswer::count();

        // Lakukan berbagai GET request
        $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-03-15']))->assertOk();
        $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2028-02-29']))->assertOk();
        $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => 'invalid']))->assertOk();
        $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-02-29']))->assertOk();
        $this->actingAs($pemagang)->get(route('pemagang.registration.form'))->assertOk();

        // Verifikasi database 100% identik
        $this->assertEquals($regCountBefore, IR::count());
        $this->assertEquals($ansCountBefore, \App\Models\RegistrationCustomAnswer::count());

        $regAfter = $regBefore->fresh();
        $this->assertEquals('2026-08-20', substr((string)$regAfter->start_date, 0, 10));
        $this->assertEquals($regBefore->updated_at->toDateTimeString(), $regAfter->updated_at->toDateTimeString());

        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $regBefore->id,
            'field_key'       => 'pertanyaan_draf_aman',
            'answer_value'    => 'Jawaban Asli Tidak Boleh Berubah',
        ]);
    }

    /**
     * 43. ISSUE 2 & 3 — Calendar date validation matrix and invalid queries produce safe response without HTTP 500 or DB mutation.
     */
    public function test_calendar_date_validation_matrix_and_invalid_queries_produce_safe_response_without_http_500_or_db_mutation(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Terbitkan skema 2027
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Terbitkan skema 2028 (karena 2028 adalah tahun kabisat / leap year)
        $draft2028 = $this->service->getOrCreateDraft(2028, $admin);
        $pub2028 = $this->service->publishVersion($draft2028, $admin);

        $initialRegCount = IR::count();
        $initialAnsCount = \App\Models\RegistrationCustomAnswer::count();

        // 1. '2027-03-15': Valid -> Schema 2027 loaded, input date contains value="2027-03-15"
        $res1 = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-03-15']));
        $res1->assertOk();
        $this->assertStartDateInputValue($res1, '2027-03-15');
        $res1->assertSee('value="' . $pub2027->version_code . '"', false);
        $res1->assertDontSee('Format tanggal mulai magang yang dipilih tidak valid');

        // 2. '2028-02-29': Valid calendar date (leap year) -> 200 OK, no HTTP 500
        $res2 = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2028-02-29']));
        $res2->assertOk();
        $this->assertStartDateInputValue($res2, '2028-02-29');
        $res2->assertSee('value="' . $pub2028->version_code . '"', false);
        $res2->assertDontSee('Format tanggal mulai magang yang dipilih tidak valid');

        // 3. '2027-02-29': Invalid calendar date (2027 bukan tahun kabisat) -> Safe response, NO 500, displays validation banner
        $res3 = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-02-29']));
        $res3->assertOk();
        $res3->assertSee('Format tanggal mulai magang yang dipilih tidak valid');

        // 4. '2027-99-99': Invalid day/month -> Safe response, NO 500, displays validation banner
        $res4 = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => '2027-99-99']));
        $res4->assertOk();
        $res4->assertSee('Format tanggal mulai magang yang dipilih tidak valid');

        // 5. 'invalid': Invalid non-date string -> Safe response, NO 500, displays validation banner
        $res5 = $this->actingAs($pemagang)->get(route('pemagang.registration.form', ['start_date' => 'invalid']));
        $res5->assertOk();
        $res5->assertSee('Format tanggal mulai magang yang dipilih tidak valid');

        // Verifikasi mutasi database 100% nol
        $this->assertEquals($initialRegCount, IR::count());
        $this->assertEquals($initialAnsCount, \App\Models\RegistrationCustomAnswer::count());
    }

    /**
     * 44. FIX 1 — Non-scalar start_date handling: GET with array or nested array is safely handled without TypeError or DB mutation.
     */
    public function test_get_start_date_array_and_nested_array_handled_safely_without_typeerror_or_db_mutation(): void
    {
        $pemagang = $this->createPemagang();

        $initialRegCount = IR::count();
        $initialAnsCount = \App\Models\RegistrationCustomAnswer::count();

        // 1. Array query: ?start_date[]=2027-03-15
        $resArray = $this->actingAs($pemagang)->get('/pemagang/daftar?start_date[]=2027-03-15');
        $resArray->assertOk();
        $resArray->assertSee('Format tanggal mulai magang yang dipilih tidak valid');

        // 2. Nested array query: ?start_date[0][1]=2027-03-15
        $resNested = $this->actingAs($pemagang)->get('/pemagang/daftar?start_date[0][1]=2027-03-15');
        $resNested->assertOk();
        $resNested->assertSee('Format tanggal mulai magang yang dipilih tidak valid');

        // Verifikasi 0 mutasi database
        $this->assertEquals($initialRegCount, IR::count());
        $this->assertEquals($initialAnsCount, \App\Models\RegistrationCustomAnswer::count());
    }

    /**
     * 45. FIX 1 — Non-scalar start_date handling: POST with array or nested array is safely rejected without HTTP 500 or DB mutation.
     */
    public function test_post_start_date_array_and_nested_array_rejected_safely_without_http_500_or_db_mutation(): void
    {
        $pemagang = $this->createPemagang();

        $initialRegCount = IR::count();
        $initialAnsCount = \App\Models\RegistrationCustomAnswer::count();

        // 1. POST submission resmi dengan start_date berupa array
        $resPostArray = $this->actingAs($pemagang)->post(
            route('pemagang.registration.store'),
            $this->validRegistrationPayload(['start_date' => ['2027-03-15']])
        );
        $resPostArray->assertSessionHasErrors(['start_date']);

        // 2. POST draft dengan start_date berupa nested array
        $resDraftNested = $this->actingAs($pemagang)->post(
            route('pemagang.registration.draft'),
            $this->validRegistrationPayload(['start_date' => [['nested' => '2027-03-15']]])
        );
        $resDraftNested->assertSessionHasErrors(['start_date']);

        // Verifikasi 0 mutasi database
        $this->assertEquals($initialRegCount, IR::count());
        $this->assertEquals($initialAnsCount, \App\Models\RegistrationCustomAnswer::count());
    }

    /**
     * 46. FIX 2 — Validated query date precedence: GET with valid preview date overrides flashed old input from different year.
     */
    public function test_get_valid_date_precedence_over_flashed_old_start_date_from_different_year(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Terbitkan skema 2027 dengan pertanyaan custom
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $this->service->addFieldToDraft($draft2027, [
            'field_key'   => 'pertanyaan_2027',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan 2027',
            'is_required' => false,
        ]);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Akses GET dengan flashed old input dari tahun 2026, tetapi pengguna memilih preview 2027-03-15
        $res = $this->actingAs($pemagang)
            ->withSession([
                '_old_input' => [
                    'start_date'      => '2026-05-01',
                    'supervisor_name' => 'Pembimbing Tetap Dipertahankan',
                ],
            ])
            ->get(route('pemagang.registration.form', ['start_date' => '2027-03-15']));

        $res->assertOk();

        // 1. Input date name="start_date" memprioritaskan query valid 2027-03-15, BUKAN flashed 2026-05-01
        $this->assertStartDateInputValue($res, '2027-03-15');

        // 2. Field lain tetap mempertahankan nilai dari old input
        $res->assertSee('value="Pembimbing Tetap Dipertahankan"', false);

        // 3. Hidden schema version dan schema ID sesuai dengan skema 2027
        $res->assertSee('value="' . $pub2027->version_code . '"', false);
        $res->assertSee('value="' . $pub2027->id . '"', false);

        // 4. Pertanyaan 2027 ditampilkan
        $res->assertSee('pertanyaan_2027');
    }

    /**
     * 47. FIX 2 — Historical snapshot registration preserves immutable date even with valid query and flashed old input.
     */
    public function test_historical_snapshot_registration_preserves_immutable_date_even_with_valid_query_and_flashed_old(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Terbitkan skema 2026
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        // Terbitkan skema 2027
        $draft2027 = $this->service->getOrCreateDraft(2027, $admin);
        $pub2027 = $this->service->publishVersion($draft2027, $admin);

        // Pendaftar melakukan submit final pada periode 2026
        $this->actingAs($pemagang)->post(route('pemagang.registration.store'), $this->validRegistrationPayload([
            'start_date'          => '2026-09-01',
            'form_schema_version' => $pub2026->version_code,
        ]));

        $reg = IR::where('user_id', $pemagang->id)->first();
        $this->assertNotNull($reg);
        $this->assertNotNull($reg->formSnapshot);

        // Pengguna dengan snapshot 2026 membuka form dengan query preview 2027-03-15 dan flashed old 2028-01-01
        $res = $this->actingAs($pemagang)
            ->withSession([
                '_old_input' => [
                    'start_date' => '2028-01-01',
                ],
            ])
            ->get(route('pemagang.registration.form', ['start_date' => '2027-03-15']));

        $res->assertOk();

        // 1. Input date name="start_date" MUTLAK mempertahankan tanggal snapshot historis 2026-09-01
        $this->assertStartDateInputValue($res, '2026-09-01');

        // 2. Skema aktif yang dibinding adalah skema snapshot 2026, BUKAN 2027
        $res->assertSee('value="' . $pub2026->version_code . '"', false);
        $res->assertSee('value="' . $pub2026->id . '"', false);
        $res->assertDontSee('value="' . $pub2027->version_code . '"', false);
    }

    /**
     * 48. S17.18G.5-R03 BLOCKER — POST invalid date (array/malformed) redirect to GET renders cleanly without TypeError, HTTP 500, or DB mutation.
     */
    public function test_post_invalid_start_date_redirect_to_get_renders_cleanly_without_typeerror_or_500(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Terbitkan skema 2026
        $draft2026 = $this->service->getOrCreateDraft(2026, $admin);
        $pub2026 = $this->service->publishVersion($draft2026, $admin);

        $initialRegCount = IR::count();
        $initialAnsCount = \App\Models\RegistrationCustomAnswer::count();

        // 1. Kirim POST dengan start_date berupa array dan field lain valid
        $postPayload = $this->validRegistrationPayload([
            'fullname'            => 'Budi Santoso Diverifikasi',
            'start_date'          => ['2027-03-15'],
            'form_schema_version' => $pub2026->version_code,
        ]);

        $resPost = $this->actingAs($pemagang)
            ->from(route('pemagang.registration.form'))
            ->post(route('pemagang.registration.store'), $postPayload);

        // Harus redirect kembali ke form dengan session error
        $resPost->assertRedirect(route('pemagang.registration.form'));
        $resPost->assertSessionHasErrors(['start_date']);

        // 2. Akses halaman GET formulir hasil redirect (mengonsumsi session error dan flashed _old_input)
        $resGet = $this->actingAs($pemagang)
            ->get(route('pemagang.registration.form'));

        // Harus HTTP 200 OK — DILARANG TypeError atau HTTP 500
        $resGet->assertOk();

        // Helper Blade toDateInput dan $old TIDAK boleh merender nilai malformed/array ke input HTML
        $this->assertStartDateInputValue($resGet, '');

        // Old input field yang sah (fullname) WAJIB tetap dipulihkan dengan benar
        $resGet->assertSee('value="Budi Santoso Diverifikasi"', false);

        // Pesan error validasi kalender harus tampil ramah pengguna
        $resGet->assertSee('Format tanggal mulai magang yang dipilih tidak valid');

        // 3. Verifikasi 100% database, snapshot, answers, dan files TIDAK bermutasi
        $this->assertEquals($initialRegCount, IR::count());
        $this->assertEquals($initialAnsCount, \App\Models\RegistrationCustomAnswer::count());
    }
}

