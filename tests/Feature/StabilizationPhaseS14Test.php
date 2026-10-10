<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternCertificate;
use App\Models\InternLoa;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use App\Models\RegistrationAttachment;
use App\Models\RegistrationContactLink;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FollowUpService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StabilizationPhaseS14Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private Institution $institution;
    private Faculty $faculty;
    private StudyProgram $studyProgram;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('s14_', true);

        $this->admin = User::create([
            'name'     => 'Admin S14 ' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'HR S14 ' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'Pemagang S14 ' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->institution = Institution::create([
            'name'                => 'SMK Mitra S14 ' . $this->unique,
            'correspondence_unit' => 'Hubin',
            'is_active'           => true,
        ]);

        $this->faculty = Faculty::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Fakultas S14 ' . substr($this->unique, 0, 8),
        ]);

        $this->studyProgram = StudyProgram::create([
            'faculty_id' => $this->faculty->id,
            'name'       => 'Teknik Informatika S14 ' . substr($this->unique, 0, 8),
        ]);
    }

    /**
     * S14-1: Database menolak penambahan nama fakultas duplikat pada institusi yang sama.
     */
    public function test_cannot_insert_duplicate_faculty_for_same_institution(): void
    {
        $inst = Institution::create([
            'name'                => 'Universitas Unik ' . $this->unique,
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Teknik Industri',
        ]);

        $this->expectException(QueryException::class);

        // Percobaan insert fakultas kedua dengan nama yang sama pada institusi yang sama wajib gagal di DB
        Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Teknik Industri',
        ]);
    }

    /**
     * S14-2: Database mengizinkan nama fakultas yang sama untuk institusi yang berbeda (scoped unique).
     */
    public function test_can_insert_same_faculty_name_for_different_institutions(): void
    {
        $instA = Institution::create([
            'name'                => 'Institut Teknologi A ' . $this->unique,
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        $instB = Institution::create([
            'name'                => 'Institut Teknologi B ' . $this->unique,
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        $facA = Faculty::create([
            'institution_id' => $instA->id,
            'name'           => 'Fakultas Ilmu Komputer',
        ]);

        $facB = Faculty::create([
            'institution_id' => $instB->id,
            'name'           => 'Fakultas Ilmu Komputer',
        ]);

        $this->assertNotEquals($facA->id, $facB->id);
        $this->assertEquals('Fakultas Ilmu Komputer', $facA->name);
        $this->assertEquals('Fakultas Ilmu Komputer', $facB->name);
        $this->assertEquals($instA->id, $facA->institution_id);
        $this->assertEquals($instB->id, $facB->institution_id);
    }

    /**
     * S14-3: Database menolak penambahan program studi duplikat pada fakultas yang sama.
     */
    public function test_cannot_insert_duplicate_study_program_for_same_faculty(): void
    {
        $inst = Institution::create([
            'name'                => 'Universitas Prodi ' . $this->unique,
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        $fac = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Sains ' . $this->unique,
        ]);

        StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'Rekayasa Perangkat Lunak',
        ]);

        $this->expectException(QueryException::class);

        // Percobaan insert prodi kedua dengan nama yang sama pada fakultas yang sama wajib gagal di DB
        StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'Rekayasa Perangkat Lunak',
        ]);
    }

    /**
     * S14-4: Database mengizinkan nama prodi yang sama untuk fakultas yang berbeda (scoped unique).
     */
    public function test_can_insert_same_study_program_name_for_different_faculties(): void
    {
        $inst = Institution::create([
            'name'                => 'Universitas Kampus ' . $this->unique,
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        $facA = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Rekayasa ' . $this->unique,
        ]);

        $facB = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Sekolah Vokasi ' . $this->unique,
        ]);

        $spA = StudyProgram::create([
            'faculty_id' => $facA->id,
            'name'       => 'Teknologi Informasi',
        ]);

        $spB = StudyProgram::create([
            'faculty_id' => $facB->id,
            'name'       => 'Teknologi Informasi',
        ]);

        $this->assertNotEquals($spA->id, $spB->id);
        $this->assertEquals('Teknologi Informasi', $spA->name);
        $this->assertEquals('Teknologi Informasi', $spB->name);
        $this->assertEquals($facA->id, $spA->faculty_id);
        $this->assertEquals($facB->id, $spB->faculty_id);
    }

    /**
     * S14-5: Foreign key constraint pada mous.superseded_by_id menolak ID yang tidak eksis di tabel mous.
     */
    public function test_mous_superseded_by_id_foreign_key_enforcement(): void
    {
        $inst = Institution::create([
            'name'                => 'Universitas MOU ' . $this->unique,
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        $mou1 = Mou::create([
            'public_uuid'                  => (string) Str::uuid(),
            'institution_id'               => $inst->id,
            'company_mou_number'           => 'MOU-S14-001-' . $this->unique,
            'first_party_institution_name' => $inst->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'PT Tujuh Solusi Berkelanjutan',
            'second_party_signatory_name'  => 'Direktur Utama',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
        ]);

        // 1. Memasukkan ID non-existent pada superseded_by_id wajib gagal dengan QueryException
        $invalidId = 99999999;
        try {
            DB::table('mous')->where('id', $mou1->id)->update(['superseded_by_id' => $invalidId]);
            $this->fail('Seharusnya QueryException dilempar karena foreign key constraint violation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
        }

        // 2. Memasukkan ID MOU yang valid wajib berhasil
        $mou2 = Mou::create([
            'public_uuid'                  => (string) Str::uuid(),
            'institution_id'               => $inst->id,
            'company_mou_number'           => 'MOU-S14-002-' . $this->unique,
            'first_party_institution_name' => $inst->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'PT Tujuh Solusi Berkelanjutan',
            'second_party_signatory_name'  => 'Direktur Utama',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
        ]);

        $mou1->update([
            'superseded_by_id' => $mou2->id,
            'status'           => Mou::STATUS_SUPERSEDED,
        ]);

        $mou1->refresh();
        $this->assertEquals($mou2->id, $mou1->superseded_by_id);
        $this->assertEquals($mou2->id, $mou1->supersededBy->id);
    }

    /**
     * S14-6: Menghapus rekor MOU pengganti secara permanen mengeset superseded_by_id menjadi null (nullOnDelete).
     */
    public function test_deleting_superseding_mou_sets_superseded_by_id_to_null(): void
    {
        $inst = Institution::create([
            'name'                => 'Universitas MOU Null ' . $this->unique,
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        $mouOld = Mou::create([
            'public_uuid'                  => (string) Str::uuid(),
            'institution_id'               => $inst->id,
            'company_mou_number'           => 'MOU-OLD-' . $this->unique,
            'first_party_institution_name' => $inst->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'PT Tujuh Solusi Berkelanjutan',
            'second_party_signatory_name'  => 'Direktur Utama',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_SUPERSEDED,
        ]);

        $mouNew = Mou::create([
            'public_uuid'                  => (string) Str::uuid(),
            'institution_id'               => $inst->id,
            'company_mou_number'           => 'MOU-NEW-' . $this->unique,
            'first_party_institution_name' => $inst->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'PT Tujuh Solusi Berkelanjutan',
            'second_party_signatory_name'  => 'Direktur Utama',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
        ]);

        $mouOld->update(['superseded_by_id' => $mouNew->id]);
        $this->assertEquals($mouNew->id, $mouOld->fresh()->superseded_by_id);

        // Force delete pada MOU pengganti
        $mouNew->forceDelete();

        // Rekor lama tetap ada, dan kolom superseded_by_id otomatis menjadi null
        $this->assertDatabaseHas('mous', ['id' => $mouOld->id]);
        $this->assertNull($mouOld->fresh()->superseded_by_id);
    }

    /**
     * S14-7: Relasi Eloquent institution-faculty-study program dapat dimuat dengan baik secara berjenjang.
     */
    public function test_faculty_and_study_program_relations_resolve_cleanly(): void
    {
        $inst = Institution::create([
            'name'                => 'Universitas Relasi ' . $this->unique,
            'correspondence_unit' => 'Humas',
            'is_active'           => true,
        ]);

        $fac = Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Vokasi Terapan',
        ]);

        $sp = StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'D4 Manajemen Informatika',
        ]);

        // Verifikasi relasi bolak-balik
        $this->assertEquals($inst->id, $fac->institution->id);
        $this->assertTrue($fac->studyPrograms->contains('id', $sp->id));
        $this->assertEquals($fac->id, $sp->faculty->id);
    }

    /* =========================================================================
     * S14 CANONICAL TESTS: HARDENED DATABASE CONSTRAINTS & INTEGRITY VERIFICATION
     * ========================================================================= */

    /**
     * S14-8: Database constraint menolak duplikasi siklus follow-up untuk kontak dan tahun yang sama.
     */
    public function test_cannot_insert_duplicate_followup_cycle_for_same_contact_and_year(): void
    {
        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Kontrak Duplikat ' . $this->unique,
            'phone'          => '081211112222',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
        ]);

        $this->expectException(QueryException::class);

        // Percobaan insert siklus kedua untuk kontak yang sama pada tahun yang sama wajib ditolak oleh DB
        FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
        ]);
    }

    /**
     * S14-9: Database constraint menolak duplikasi nomor attempt dalam satu siklus follow-up yang sama.
     */
    public function test_cannot_insert_duplicate_attempt_number_in_same_cycle(): void
    {
        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Kontrak Attempt Unique ' . $this->unique,
            'phone'          => '081233334444',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
        ]);

        FollowUpAttempt::create([
            'public_uuid'       => (string) Str::uuid(),
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff->id,
            'followed_up_at'    => now(),
            'channel'           => 'whatsapp',
            'result'            => 'no_answer',
        ]);

        $this->expectException(QueryException::class);

        // Percobaan insert attempt dengan attempt_number = 1 lagi pada siklus yang sama wajib ditolak DB
        FollowUpAttempt::create([
            'public_uuid'       => (string) Str::uuid(),
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff->id,
            'followed_up_at'    => now(),
            'channel'           => 'whatsapp',
            'result'            => 'no_answer',
        ]);
    }

    /**
     * S14-10: Domain service FollowUpService menegakkan batas maksimal 3 attempt dan transisi status terminal.
     */
    public function test_followup_service_enforces_max_three_attempts_and_terminal_rules(): void
    {
        /** @var FollowUpService $service */
        $service = app(FollowUpService::class);

        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Kontrak Service Rules ' . $this->unique,
            'phone'          => '081255556666',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $cycle = $service->createCycle($contact, 2026, $this->hrStaff);
        $service->claimLock($cycle, $this->hrStaff);

        // Attempt 1: no_answer (status tetap in_progress)
        $attempt1 = $service->recordAttempt($cycle, [
            'result'   => 'no_answer',
            'channel'  => 'whatsapp',
            'notes'    => 'Panggilan 1',
        ], $this->hrStaff);
        $this->assertEquals(1, $attempt1->attempt_number);
        $this->assertEquals(FollowUpCycle::STATUS_IN_PROGRESS, $cycle->fresh()->status);

        // Attempt 2: call_back (status menjadi waiting_response, non-terminal)
        $attempt2 = $service->recordAttempt($cycle, [
            'result'            => 'call_back',
            'channel'           => 'whatsapp',
            'next_followup_at'  => now()->addDays(2)->toDateString(),
            'notes'             => 'Minta dihubungi lagi',
        ], $this->hrStaff);
        $this->assertEquals(2, $attempt2->attempt_number);
        $this->assertEquals(FollowUpCycle::STATUS_WAITING_RESPONSE, $cycle->fresh()->status);

        // Percobaan menutup dengan status non-terminal 'call_back' wajib ditolak
        try {
            $service->completeCycle($cycle, FollowUpCycle::RESULT_CALL_BACK, 'Catatan', $this->hrStaff);
            $this->fail('Seharusnya DomainException dilempar saat menutup siklus dengan call_back.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('non-terminal', $e->getMessage());
        }

        // Attempt 3: no_answer (mencapai tepat 3 attempt)
        $attempt3 = $service->recordAttempt($cycle, [
            'result'   => 'no_answer',
            'channel'  => 'whatsapp',
            'notes'    => 'Panggilan 3 tanpa respon',
        ], $this->hrStaff);
        $this->assertEquals(3, $attempt3->attempt_number);

        // Percobaan attempt ke-4 pada siklus yang sudah 3 attempt wajib ditolak service
        try {
            $service->recordAttempt($cycle, [
                'result'  => 'no_answer',
                'channel' => 'whatsapp',
            ], $this->hrStaff);
            $this->fail('Seharusnya DomainException dilempar untuk attempt ke-4.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('maksimal 3', $e->getMessage());
        }

        // Menutup siklus dengan no_response setelah 3 attempt berhasil dan status menjadi closed_no_response
        $completedCycle = $service->completeCycle($cycle, FollowUpCycle::RESULT_NO_RESPONSE, '3x tidak menjawab', $this->hrStaff);
        $this->assertEquals(FollowUpCycle::STATUS_CLOSED_NO_RESPONSE, $completedCycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_NO_RESPONSE, $completedCycle->final_result);
    }

    /**
     * S14-11: Satu user dapat memiliki beberapa registrasi lintas periode tanpa benturan constraint unik.
     */
    public function test_user_can_have_multiple_registrations_across_periods_without_unique_constraint_violation(): void
    {
        $reg2025 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Multi 2025 ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081299992025',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'is_draft'          => false,
        ]);
        $reg2025->created_at = Carbon::create(2025, 3, 1, 10, 0, 0);
        $reg2025->save();

        $reg2026 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Multi 2026 ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081299992026',
            'internship_status' => InternshipRegistration::STATUS_ACTIVE,
            'is_draft'          => false,
        ]);
        $reg2026->created_at = Carbon::create(2026, 3, 1, 10, 0, 0);
        $reg2026->save();

        $reg2027 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Multi 2027 ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081299992027',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg2027->created_at = Carbon::create(2027, 3, 1, 10, 0, 0);
        $reg2027->save();

        $userRegistrations = InternshipRegistration::where('user_id', $this->pemagang->id)->get();
        $this->assertCount(3, $userRegistrations);
        $this->assertEquals(2025, $reg2025->created_at->year);
        $this->assertEquals(2026, $reg2026->created_at->year);
        $this->assertEquals(2027, $reg2027->created_at->year);
    }

    /**
     * S14-12: Soft-delete pada satu registrasi mempertahankan registrasi lain dan dokumen historis.
     */
    public function test_soft_deleting_registration_preserves_other_registrations_and_documents(): void
    {
        $reg1 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang SoftDelete ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081288881111',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'is_draft'          => false,
        ]);

        $loa = InternLoa::create([
            'intern_id'            => $reg1->id,
            'loa_number'           => 'LOA-S14-' . $this->unique,
            'accepted_start_date'  => now()->toDateString(),
            'accepted_end_date'    => now()->addMonths(3)->toDateString(),
            'company_name'         => 'PT Tujuh Solusi Berkelanjutan',
            'signatory_name'       => 'Pimpinan HR',
        ]);

        $cert = InternCertificate::create([
            'intern_id'          => $reg1->id,
            'certificate_number' => 'CERT-S14-' . $this->unique,
            'company_name'       => 'PT Tujuh Solusi Berkelanjutan',
            'signatory_name'     => 'Direktur Utama',
        ]);

        $reg2 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Tetap Aktif ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081288882222',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);

        // Soft-delete registrasi 1
        $reg1->delete();

        $this->assertSoftDeleted('internship_registrations', ['id' => $reg1->id]);
        $this->assertDatabaseHas('internship_registrations', ['id' => $reg2->id, 'deleted_at' => null]);

        // Dokumen LOA dan Sertifikat fisik di DB tetap terpelihara (tidak cascade hard delete)
        $this->assertDatabaseHas('intern_loas', ['id' => $loa->id, 'intern_id' => $reg1->id]);
        $this->assertDatabaseHas('intern_certificates', ['id' => $cert->id, 'intern_id' => $reg1->id]);
    }

    /**
     * S14-13: registration_contact_links menegakkan unique constraint dan integritas foreign key.
     */
    public function test_registration_contact_links_enforces_unique_and_foreign_keys(): void
    {
        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Pembimbing Link ' . $this->unique,
            'phone'          => '081277778888',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $reg = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Link ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081277779999',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);

        $link = RegistrationContactLink::create([
            'public_uuid'                => (string) Str::uuid(),
            'internship_registration_id' => $reg->id,
            'institution_contact_id'     => $contact->id,
            'relation_year'              => 2026,
            'is_primary'                 => true,
            'source'                     => 'registration_form',
        ]);

        $this->assertNotNull($link->id);

        // 1. Percobaan membuat link duplikat untuk reg & contact yang sama wajib ditolak DB (reg_contact_unique)
        try {
            RegistrationContactLink::create([
                'public_uuid'                => (string) Str::uuid(),
                'internship_registration_id' => $reg->id,
                'institution_contact_id'     => $contact->id,
            ]);
            $this->fail('Seharusnya QueryException dilempar karena duplicate link.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate entry', strtolower($e->getMessage()));
        }

        // 2. Percobaan membuat link dengan contact ID non-existent wajib ditolak DB
        try {
            RegistrationContactLink::create([
                'public_uuid'                => (string) Str::uuid(),
                'internship_registration_id' => $reg->id,
                'institution_contact_id'     => 99999999,
            ]);
            $this->fail('Seharusnya QueryException dilempar karena FK contact invalid.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
        }
    }

    /**
     * S14-14: registration_attachments menegakkan foreign key restrictOnDelete mencegah orphan deletion.
     */
    public function test_registration_attachments_enforces_restrict_on_delete_foreign_key(): void
    {
        $reg = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Attachment ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081266667777',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktm_portfolio',
            'file_path'                  => 'attachments/sample.pdf',
            'original_name'              => 'sample.pdf',
            'file_size'                  => 102400,
            'mime_type'                  => 'application/pdf',
        ]);

        $this->assertNotNull($attachment->id);

        // Percobaan hard-delete (forceDelete) pada registrasi yang masih memiliki lampiran wajib ditolak oleh DB
        $this->expectException(QueryException::class);
        DB::table('internship_registrations')->where('id', $reg->id)->delete();
    }

    /**
     * S14-15: spreadsheet_row_syncs menegakkan constraint unik external_sync_id dan kombinasi syncable.
     */
    public function test_spreadsheet_row_syncs_enforces_unique_external_sync_id_and_syncable_combination(): void
    {
        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Spreadsheet Pendaftaran Magang']
        );

        $syncId1 = 'REG-S14-001-' . $this->unique;
        $syncId2 = 'REG-S14-002-' . $this->unique;

        $rowSync1 = SpreadsheetRowSync::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $conn->id,
            'syncable_type'             => 'internship_registration',
            'syncable_id'               => 101,
            'external_sync_id'          => $syncId1,
            'target_sheet_tab'          => '2026',
            'schema_version'            => 'v1',
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
        ]);

        $this->assertNotNull($rowSync1->id);

        // 1. Percobaan insert syncable yang sama pada connection yang sama wajib ditolak DB
        try {
            SpreadsheetRowSync::create([
                'public_uuid'               => (string) Str::uuid(),
                'spreadsheet_connection_id' => $conn->id,
                'syncable_type'             => 'internship_registration',
                'syncable_id'               => 101,
                'external_sync_id'          => $syncId2,
                'target_sheet_tab'          => '2026',
                'schema_version'            => 'v1',
                'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
            ]);
            $this->fail('Seharusnya QueryException dilempar karena duplicate syncable combination.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate entry', strtolower($e->getMessage()));
        }

        // 2. Percobaan insert external_sync_id yang sama untuk syncable berbeda wajib ditolak DB
        try {
            SpreadsheetRowSync::create([
                'public_uuid'               => (string) Str::uuid(),
                'spreadsheet_connection_id' => $conn->id,
                'syncable_type'             => 'internship_registration',
                'syncable_id'               => 102,
                'external_sync_id'          => $syncId1,
                'target_sheet_tab'          => '2026',
                'schema_version'            => 'v1',
                'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
            ]);
            $this->fail('Seharusnya QueryException dilempar karena duplicate external_sync_id.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate entry', strtolower($e->getMessage()));
        }
    }

    /**
     * S14-16: spreadsheet_schema_versions dan column mappings menegakkan composite unique constraints.
     */
    public function test_spreadsheet_schema_versions_and_mappings_enforce_composite_unique_constraints(): void
    {
        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Spreadsheet Pendaftaran Magang']
        );

        $testYear = 2099; // Tahun uji khusus agar tidak bentrok dengan seeder

        $version = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $conn->id,
            'year'                      => $testYear,
            'sheet_tab_name'            => (string) $testYear,
            'schema_version'            => 'v1',
            'is_active'                 => true,
        ]);

        $this->assertNotNull($version->id);

        // 1. Percobaan insert schema version duplikat untuk connection, year, dan version yang sama wajib ditolak DB
        try {
            SpreadsheetSchemaVersion::create([
                'public_uuid'               => (string) Str::uuid(),
                'spreadsheet_connection_id' => $conn->id,
                'year'                      => $testYear,
                'sheet_tab_name'            => (string) $testYear,
                'schema_version'            => 'v1',
                'is_active'                 => true,
            ]);
            $this->fail('Seharusnya QueryException dilempar karena duplicate schema version.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate entry', strtolower($e->getMessage()));
        }

        // 2. Buat mapping kolom 1
        SpreadsheetColumnMapping::create([
            'public_uuid'                   => (string) Str::uuid(),
            'spreadsheet_schema_version_id' => $version->id,
            'column_order'                  => 1,
            'sheet_header'                  => 'Timestamp',
            'target_entity'                 => 'internship_registration',
            'target_field'                  => 'created_at',
        ]);

        // 3. Percobaan insert mapping dengan column_order yang sama wajib ditolak DB
        try {
            SpreadsheetColumnMapping::create([
                'public_uuid'                   => (string) Str::uuid(),
                'spreadsheet_schema_version_id' => $version->id,
                'column_order'                  => 1,
                'sheet_header'                  => 'Header Lain',
                'target_entity'                 => 'internship_registration',
                'target_field'                  => 'fullname',
            ]);
            $this->fail('Seharusnya QueryException dilempar karena duplicate column_order.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate entry', strtolower($e->getMessage()));
        }

        // 4. Percobaan insert mapping dengan sheet_header yang sama wajib ditolak DB
        try {
            SpreadsheetColumnMapping::create([
                'public_uuid'                   => (string) Str::uuid(),
                'spreadsheet_schema_version_id' => $version->id,
                'column_order'                  => 2,
                'sheet_header'                  => 'Timestamp',
                'target_entity'                 => 'internship_registration',
                'target_field'                  => 'fullname',
            ]);
            $this->fail('Seharusnya QueryException dilempar karena duplicate sheet_header.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate entry', strtolower($e->getMessage()));
        }
    }

    /**
     * S14-17: Database menolak penyimpanan registrasi magang dengan foreign key relasi yang tidak valid.
     */
    public function test_database_rejects_invalid_foreign_keys_for_registration_relations(): void
    {
        $invalidId = 99999999;

        // 1. Institution ID tidak valid
        try {
            InternshipRegistration::create([
                'user_id'           => $this->pemagang->id,
                'institution_id'    => $invalidId,
                'faculty_id'        => $this->faculty->id,
                'study_program_id'  => $this->studyProgram->id,
                'fullname'          => 'Pemagang Invalid Inst ' . $this->unique,
                'gender'            => 'L',
                'phone_number'      => '081299990001',
            ]);
            $this->fail('Seharusnya QueryException dilempar karena FK institution_id invalid.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
        }

        // 2. Faculty ID tidak valid
        try {
            InternshipRegistration::create([
                'user_id'           => $this->pemagang->id,
                'institution_id'    => $this->institution->id,
                'faculty_id'        => $invalidId,
                'study_program_id'  => $this->studyProgram->id,
                'fullname'          => 'Pemagang Invalid Fac ' . $this->unique,
                'gender'            => 'L',
                'phone_number'      => '081299990002',
            ]);
            $this->fail('Seharusnya QueryException dilempar karena FK faculty_id invalid.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
        }

        // 3. Study Program ID tidak valid
        try {
            InternshipRegistration::create([
                'user_id'           => $this->pemagang->id,
                'institution_id'    => $this->institution->id,
                'faculty_id'        => $this->faculty->id,
                'study_program_id'  => $invalidId,
                'fullname'          => 'Pemagang Invalid Prodi ' . $this->unique,
                'gender'            => 'L',
                'phone_number'      => '081299990003',
            ]);
            $this->fail('Seharusnya QueryException dilempar karena FK study_program_id invalid.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
        }

        // 4. User ID tidak valid
        try {
            InternshipRegistration::create([
                'user_id'           => $invalidId,
                'institution_id'    => $this->institution->id,
                'faculty_id'        => $this->faculty->id,
                'study_program_id'  => $this->studyProgram->id,
                'fullname'          => 'Pemagang Invalid User ' . $this->unique,
                'gender'            => 'L',
                'phone_number'      => '081299990004',
            ]);
            $this->fail('Seharusnya QueryException dilempar karena FK user_id invalid.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
        }
    }
}

