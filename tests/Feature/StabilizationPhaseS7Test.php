<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StabilizationPhaseS7Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff1;
    private User $hrStaff2;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('s7_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff1 = User::create([
            'name'     => 'hr1_' . substr($this->unique, 0, 8),
            'email'    => 'hr1_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->hrStaff2 = User::create([
            'name'     => 'hr2_' . substr($this->unique, 0, 8),
            'email'    => 'hr2_' . $this->unique . '@test.com',
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

    private function lockCycleFor(FollowUpCycle $cycle, User $user): void
    {
        FollowUpCycle::where('id', $cycle->id)->update([
            'locked_by'       => $user->id,
            'locked_at'       => now(),
            'lock_expires_at' => now()->addMinutes(15),
        ]);
        $cycle->refresh();
    }

    public function test_attempt_1_2_and_3_succeed_while_attempt_4_is_strictly_rejected_server_side(): void
    {
        $institution = Institution::create(['name' => 'Kampus S7 Attempts ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing S7 ' . $this->unique,
            'phone'          => '081299990001',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt 1
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp1 = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'no_answer',
            'response_text'  => 'Centang dua belum dibaca.',
        ]);
        $resp1->assertRedirect();
        $cycle->refresh();
        $this->assertEquals(1, $cycle->current_attempt_count);
        $this->assertEquals(1, $cycle->attempts()->count());

        // Attempt 2
        $this->lockCycleFor($cycle, $this->hrStaff2);
        $resp2 = $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'call_back',
            'response_text'  => 'Dosen sedang rapat.',
        ]);
        $resp2->assertRedirect();
        $cycle->refresh();
        $this->assertEquals(2, $cycle->current_attempt_count);
        $this->assertEquals(2, $cycle->attempts()->count());

        // Attempt 3
        $this->lockCycleFor($cycle, $this->admin);
        $resp3 = $this->actingAs($this->admin)->post(route('admin.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'email',
            'result'         => 'responded',
            'response_text'  => 'Dosen menyambut proposal kemitraan.',
        ]);
        $resp3->assertRedirect();
        $cycle->refresh();
        $this->assertEquals(3, $cycle->current_attempt_count);
        $this->assertEquals(3, $cycle->attempts()->count());
        $this->assertFalse($cycle->canAttempt());

        // Attempt 4 via Web Form: Rejected
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp4Web = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $resp4Web->assertRedirect();
        $resp4Web->assertSessionHas('error');

        // Attempt 4 via JSON: HTTP 422
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp4Json = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);
        $resp4Json->assertStatus(422);
        $resp4Json->assertJsonFragment([
            'message' => 'Siklus follow-up telah mencapai batas maksimal 3 kali percobaan.',
        ]);

        $this->assertEquals(3, $cycle->attempts()->count());
    }

    public function test_different_hr_staff_can_perform_consecutive_attempts_on_the_same_cycle(): void
    {
        $institution = Institution::create(['name' => 'Kampus Multi Performer ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dosen Performer ' . $this->unique,
            'phone'          => '081299990002',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt 1 by HR Staff 1
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'no_answer',
        ]);

        // Attempt 2 by HR Staff 2
        $this->lockCycleFor($cycle, $this->hrStaff2);
        $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'busy',
        ]);

        $attempt1 = $cycle->attempts()->where('attempt_number', 1)->first();
        $attempt2 = $cycle->attempts()->where('attempt_number', 2)->first();

        $this->assertEquals($this->hrStaff1->id, $attempt1->performed_by);
        $this->assertEquals($this->hrStaff2->id, $attempt2->performed_by);
        $this->assertEquals($this->hrStaff1->name, $attempt1->performer->name);
        $this->assertEquals($this->hrStaff2->name, $attempt2->performer->name);
    }

    public function test_registering_a_new_intern_under_same_contact_and_year_links_without_creating_duplicate_cycle(): void
    {
        $city = City::create(['name' => 'Kota S7 ' . $this->unique]);
        $division = Division::create(['name' => 'Divisi S7 ' . $this->unique]);
        $institution = Institution::create(['name' => 'Politeknik S7 ' . $this->unique, 'is_active' => true]);
        $faculty = Faculty::create(['institution_id' => $institution->id, 'name' => 'Fakultas S7 ' . $this->unique]);
        $studyProgram = StudyProgram::create(['faculty_id' => $faculty->id, 'name' => 'Prodi S7 ' . $this->unique]);

        $contact = InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Bpk Ir. Bambang Hermanto',
            'normalized_name' => 'bambang hermanto',
            'phone'           => '081234567800',
            'normalized_phone'=> '6281234567800',
            'is_active'       => true,
            'is_verified'     => true,
        ]);

        // Siklus tahun 2026 sudah ada
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Pemagang 1 mendaftar
        $user1 = User::create([
            'name'     => 'intern1_' . substr($this->unique, 0, 8),
            'email'    => 'intern1_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'user',
        ]);

        $resp1 = $this->actingAs($user1)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Calon Pemagang Satu',
            'email'               => $user1->email,
            'phone_number'        => '081111111111',
            'student_id'          => '12345671',
            'gender'              => 'Laki-laki',
            'born_date'           => '2004-01-01',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => $institution->name,
            'faculty'             => $faculty->name,
            'study_program'       => $studyProgram->name,
            'current_city'        => $city->name,
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $division->name,
            'supervisor_name'     => 'Ir. Bambang Hermanto',
            'supervisor_contact'  => '081234567800',
            'wfo_readiness'       => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'          => 1,
            'planned_duration'    => '3 Bulan',
        ]);
        $resp1->assertSessionDoesntHaveErrors();

        // Pemagang 2 mendaftar pada tahun yang sama dengan pembimbing yang sama
        $user2 = User::create([
            'name'     => 'intern2_' . substr($this->unique, 0, 8),
            'email'    => 'intern2_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'user',
        ]);

        $resp2 = $this->actingAs($user2)->post(route('pemagang.registration.store'), [
            'fullname'            => 'Calon Pemagang Dua',
            'email'               => $user2->email,
            'phone_number'        => '082222222222',
            'student_id'          => '12345672',
            'gender'              => 'Perempuan',
            'born_date'           => '2004-02-02',
            'current_status'      => 'Mahasiswa/Pelajar',
            'internship_type'     => 'Magang Mandiri',
            'institution_name'    => $institution->name,
            'faculty'             => $faculty->name,
            'study_program'       => $studyProgram->name,
            'current_city'        => $city->name,
            'internship_reason'   => 'Magang Mandiri',
            'internship_interest' => $division->name,
            'supervisor_name'     => 'Bpk Bambang Hermanto',
            'supervisor_contact'  => '081234567800',
            'wfo_readiness'       => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'          => 1,
            'planned_duration'    => '3 Bulan',
        ]);
        $resp2->assertSessionDoesntHaveErrors();

        // Siklus follow-up untuk kontak dan tahun ini tetap HANYA 1 (tidak duplikat)
        $cyclesCount = FollowUpCycle::where('institution_contact_id', $contact->id)
            ->where('cycle_year', (int) date('Y'))
            ->count();
        $this->assertEquals(1, $cyclesCount);

        // Kedua pemagang terhubung ke kontak pembimbing yang sama
        $contact->refresh();
        $linkedInterns = $contact->internshipRegistrations;
        $this->assertCount(2, $linkedInterns);
        $this->assertTrue($linkedInterns->pluck('fullname')->contains('Calon Pemagang Satu'));
        $this->assertTrue($linkedInterns->pluck('fullname')->contains('Calon Pemagang Dua'));
    }

    public function test_completed_cycle_strictly_rejects_any_new_attempts(): void
    {
        $institution = Institution::create(['name' => 'Kampus Completed S7 ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Selesai ' . $this->unique,
            'phone'          => '081299990003',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'current_attempt_count'  => 1,
            'completed_at'           => now(),
        ]);

        $resp = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonFragment([
            'message' => 'Siklus follow-up ini sudah ditutup dan tidak dapat menerima percobaan baru.',
        ]);
    }

    public function test_cannot_open_cycle_for_inactive_contact(): void
    {
        $institution = Institution::create(['name' => 'Kampus Aktif ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Pensiun ' . $this->unique,
            'phone'          => '081299990004',
            'is_active'      => false,
            'is_verified'    => true,
        ]);

        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.store'), [
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
        ]);

        $resp->assertSessionHasErrors(['institution_contact_id']);
    }

    public function test_cannot_open_cycle_for_inactive_institution(): void
    {
        $institution = Institution::create([
            'name'            => 'Kampus Nonaktif ' . $this->unique,
            'is_active'       => false,
            'inactive_reason' => 'Kerjasama ditangguhkan',
        ]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Kampus Nonaktif ' . $this->unique,
            'phone'          => '081299990005',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.store'), [
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
        ]);

        $resp->assertSessionHasErrors(['institution_id']);
    }

    public function test_database_composite_unique_constraint_prevents_duplicate_cycle_for_same_contact_and_year(): void
    {
        $institution = Institution::create(['name' => 'Kampus DB Constraint ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak DB Constraint ' . $this->unique,
            'phone'          => '081299990006',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $this->expectException(QueryException::class);

        // Langsung insert raw DB untuk memastikan database constraint menolak
        \Illuminate\Support\Facades\DB::table('followup_cycles')->insert([
            'public_uuid'            => (string) \Illuminate\Support\Str::uuid(),
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    public function test_attempt_number_is_strictly_bounded_between_1_and_3_at_model_layer(): void
    {
        $institution = Institution::create(['name' => 'Kampus Model Layer ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Model Layer ' . $this->unique,
            'phone'          => '081299990007',
            'is_active'      => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt number 4 must throw DomainException at model creating hook
        try {
            FollowUpAttempt::create([
                'followup_cycle_id' => $cycle->id,
                'attempt_number'    => 4,
                'performed_by'      => $this->hrStaff1->id,
                'followed_up_at'    => now(),
                'channel'           => 'phone',
                'result'            => 'responded',
            ]);
            $this->fail('Expected DomainException was not thrown for attempt_number 4.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('antara 1 dan 3', $e->getMessage());
        }

        // Attempt number 0 must also throw DomainException
        try {
            FollowUpAttempt::create([
                'followup_cycle_id' => $cycle->id,
                'attempt_number'    => 0,
                'performed_by'      => $this->hrStaff1->id,
                'followed_up_at'    => now(),
                'channel'           => 'phone',
                'result'            => 'responded',
            ]);
            $this->fail('Expected DomainException was not thrown for attempt_number 0.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('antara 1 dan 3', $e->getMessage());
        }
    }

    public function test_institution_id_is_automatically_synchronized_from_contact_in_cycle(): void
    {
        $institution1 = Institution::create(['name' => 'Institusi Asli ' . $this->unique, 'is_active' => true]);
        $institution2 = Institution::create(['name' => 'Institusi Salah ' . $this->unique, 'is_active' => true]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution1->id,
            'name'           => 'Kontak Sinkronisasi ' . $this->unique,
            'phone'          => '081299990008',
            'is_active'      => true,
        ]);

        // Buat cycle tanpa institution_id
        $cycle = FollowUpCycle::create([
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);
        $this->assertEquals($institution1->id, $cycle->institution_id);

        // Jika dicoba diupdate dengan institution_id yang salah, hook saving akan mengoreksinya
        $cycle->institution_id = $institution2->id;
        $cycle->save();
        $cycle->refresh();
        $this->assertEquals($institution1->id, $cycle->institution_id);
    }

    public function test_willing_completes_cycle(): void
    {
        $institution = Institution::create(['name' => 'Kampus Willing ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Willing ' . $this->unique,
            'phone'          => '081299990010',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt 1
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'responded',
        ]);

        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'willing',
            'final_notes'  => 'Kampus siap kirim 5 mahasiswa.',
        ]);
        $resp->assertRedirect();

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_WILLING, $cycle->final_result);
        $this->assertNotNull($cycle->completed_at);
        $this->assertEquals($this->hrStaff1->id, $cycle->confirmed_by);
    }

    public function test_declined_completes_cycle(): void
    {
        $institution = Institution::create(['name' => 'Kampus Declined ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Declined ' . $this->unique,
            'phone'          => '081299990011',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt 1
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'declined',
        ]);

        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'declined',
            'final_notes'  => 'Kurikulum kampus belum mewajibkan magang tahun ini.',
        ]);
        $resp->assertRedirect();

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_DECLINED, $cycle->final_result);
    }

    public function test_cooperation_stopped_completes_cycle(): void
    {
        $institution = Institution::create(['name' => 'Kampus Stopped ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Stopped ' . $this->unique,
            'phone'          => '081299990012',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt 1
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'email',
            'result'         => 'declined',
        ]);

        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'cooperation_stopped',
            'final_notes'  => 'Mitra meminta penghentian kerja sama operasional.',
        ]);
        $resp->assertRedirect();

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_COOPERATION_STOPPED, $cycle->final_result);
    }

    public function test_call_back_cannot_complete_cycle(): void
    {
        $institution = Institution::create(['name' => 'Kampus Callback ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Callback ' . $this->unique,
            'phone'          => '081299990013',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'call_back',
        ]);

        // Attempt to complete via web form: rejected by validation
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'call_back',
            'final_notes'  => 'Minta telepon lagi minggu depan.',
        ]);
        $resp->assertSessionHasErrors(['final_result']);

        // Attempt via service directly: throws DomainException
        $service = app(\App\Services\FollowUpService::class);
        try {
            $this->lockCycleFor($cycle, $this->hrStaff1);
            $service->completeCycle($cycle, 'call_back', 'Minta telepon lagi.', $this->hrStaff1);
            $this->fail('Expected DomainException was not thrown for call_back complete.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('non-terminal', $e->getMessage());
        }

        $cycle->refresh();
        $this->assertFalse($cycle->isClosed());
    }

    public function test_no_response_cannot_complete_after_attempt_1(): void
    {
        $institution = Institution::create(['name' => 'Kampus NoResp1 ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing NoResp1 ' . $this->unique,
            'phone'          => '081299990014',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // 1 Attempt only
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'no_answer',
        ]);

        // JSON complete request returns HTTP 422
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'no_response',
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonFragment([
            'message' => "Hasil akhir 'no_response' (tanpa respons) hanya dapat ditutup setelah minimal 3 kali percobaan kontak dilakukan.",
        ]);

        $cycle->refresh();
        $this->assertFalse($cycle->isClosed());
    }

    public function test_no_response_cannot_complete_after_attempt_2(): void
    {
        $institution = Institution::create(['name' => 'Kampus NoResp2 ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing NoResp2 ' . $this->unique,
            'phone'          => '081299990015',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // 2 Attempts
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'no_answer',
        ]);
        $this->lockCycleFor($cycle, $this->hrStaff2);
        $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'busy',
        ]);

        // JSON complete request returns HTTP 422
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'no_response',
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonFragment([
            'message' => "Hasil akhir 'no_response' (tanpa respons) hanya dapat ditutup setelah minimal 3 kali percobaan kontak dilakukan.",
        ]);

        $cycle->refresh();
        $this->assertFalse($cycle->isClosed());
    }

    public function test_no_response_completes_after_attempt_3(): void
    {
        $institution = Institution::create(['name' => 'Kampus NoResp3 ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing NoResp3 ' . $this->unique,
            'phone'          => '081299990016',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // 3 Attempts
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'no_answer',
        ]);
        $this->lockCycleFor($cycle, $this->hrStaff2);
        $this->actingAs($this->hrStaff2)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'busy',
        ]);
        $this->lockCycleFor($cycle, $this->admin);
        $this->actingAs($this->admin)->post(route('admin.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'email',
            'result'         => 'no_answer',
        ]);

        // Completing after attempt 3 succeeds
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'no_response',
            'final_notes'  => 'Sudah dihubungi 3x tidak ada balasan.',
        ]);
        $resp->assertRedirect();

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_CLOSED_NO_RESPONSE, $cycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_NO_RESPONSE, $cycle->final_result);
        $this->assertNotNull($cycle->completed_at);
    }

    public function test_hr_staff_cannot_complete_cycle_with_zero_attempts(): void
    {
        $institution = Institution::create(['name' => 'Kampus Zero Attempts ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Zero ' . $this->unique,
            'phone'          => '081299990017',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // 0 Attempts: HR staff attempts to complete
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.complete', $cycle), [
            'final_result' => 'willing',
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonFragment([
            'message' => 'Siklus follow-up belum memiliki riwayat percobaan kontak dan tidak dapat diselesaikan oleh staf.',
        ]);

        $cycle->refresh();
        $this->assertFalse($cycle->isClosed());
    }

    public function test_admin_can_override_and_complete_cycle_with_zero_attempts_with_audit_log(): void
    {
        $institution = Institution::create(['name' => 'Kampus Admin Override ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Admin Override ' . $this->unique,
            'phone'          => '081299990018',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $overrideReason = 'Kesepakatan langsung ditandatangani saat pertemuan pimpinan rektorat.';

        $this->lockCycleFor($cycle, $this->admin);
        $resp = $this->actingAs($this->admin)->post(route('admin.follow-up.complete', $cycle), [
            'final_result' => 'willing',
            'final_notes'  => $overrideReason,
        ]);
        $resp->assertRedirect();

        $cycle->refresh();
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_WILLING, $cycle->final_result);
        $this->assertEquals($overrideReason, $cycle->final_notes);
        $this->assertEquals($this->admin->id, $cycle->confirmed_by);

        // Activity log check
        $log = \App\Models\ActivityLog::where('action', 'followup_cycle_completed_admin_override')
            ->where('subject_type', FollowUpCycle::class)
            ->where('subject_id', $cycle->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->admin->id, $log->actor_user_id);
        $this->assertEquals('admin', $log->actor_role_snapshot);
        $this->assertStringContainsString($overrideReason, $log->description);
    }

    public function test_admin_override_cannot_complete_cycle_with_empty_notes(): void
    {
        $institution = Institution::create(['name' => 'Kampus Admin Empty Notes ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Empty Notes ' . $this->unique,
            'phone'          => '081299990019',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $this->lockCycleFor($cycle, $this->admin);
        $resp = $this->actingAs($this->admin)->postJson(route('admin.follow-up.complete', $cycle), [
            'final_result' => 'willing',
            'final_notes'  => '   ',
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonFragment([
            'message' => 'Penyelesaian siklus tanpa percobaan kontak (admin override) memerlukan catatan/alasan yang jelas.',
        ]);

        $cycle->refresh();
        $this->assertFalse($cycle->isClosed());
    }

    public function test_admin_override_cannot_make_call_back_terminal(): void
    {
        $institution = Institution::create(['name' => 'Kampus Admin Callback ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing Admin Callback ' . $this->unique,
            'phone'          => '081299990020',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempting to complete with call_back even as Admin must fail
        $service = app(\App\Services\FollowUpService::class);
        try {
            $this->lockCycleFor($cycle, $this->admin);
            $service->completeCycle($cycle, 'call_back', 'Catatan admin.', $this->admin);
            $this->fail('Expected DomainException was not thrown for admin call_back complete.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('non-terminal', $e->getMessage());
        }

        $cycle->refresh();
        $this->assertFalse($cycle->isClosed());
    }

    public function test_cannot_open_cycle_for_unverified_contact(): void
    {
        $institution = Institution::create(['name' => 'Kampus Unverified ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Calon Unverified ' . $this->unique,
            'phone'          => '081299990021',
            'is_active'      => true,
            'is_verified'    => false, // Unverified candidate contact
        ]);

        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.store'), [
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
        ]);

        $resp->assertSessionHasErrors(['institution_contact_id']);
        $this->assertEquals(0, FollowUpCycle::where('institution_contact_id', $contact->id)->count());
    }

    public function test_cannot_record_attempt_if_contact_or_institution_becomes_inactive(): void
    {
        $institution = Institution::create(['name' => 'Kampus Inactive Later ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Inactive Later ' . $this->unique,
            'phone'          => '081299990022',
            'is_active'      => true,
            'is_verified'    => true,
        ]);
        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Attempt 1 when active succeeds
        $this->lockCycleFor($cycle, $this->hrStaff1);
        $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'whatsapp',
            'result'         => 'no_answer',
        ]);
        $this->assertEquals(1, $cycle->attempts()->count());

        // Contact becomes inactive
        $contact->update(['is_active' => false]);

        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp1 = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'responded',
        ]);
        $resp1->assertStatus(422);
        $resp1->assertJsonFragment([
            'message' => 'Kontak pembimbing ini sedang nonaktif sehingga percobaan follow-up baru tidak dapat dicatat.',
        ]);

        // Re-activate contact, deactivate institution
        $contact->update(['is_active' => true]);
        $institution->update(['is_active' => false]);

        $this->lockCycleFor($cycle, $this->hrStaff1);
        $resp2 = $this->actingAs($this->hrStaff1)->postJson(route('hr.follow-up.attempts.store', $cycle), [
            'followed_up_at' => now()->format('Y-m-d H:i:s'),
            'channel'        => 'phone',
            'result'         => 'responded',
        ]);
        $resp2->assertStatus(422);
        $resp2->assertJsonFragment([
            'message' => 'Institusi ini sedang nonaktif sehingga percobaan follow-up baru tidak dapat dicatat.',
        ]);

        // Previous attempt is preserved
        $this->assertEquals(1, $cycle->attempts()->count());
    }

    public function test_same_contact_next_year_may_create_new_explicitly_confirmed_cycle(): void
    {
        $institution = Institution::create(['name' => 'Kampus Multi Year ' . $this->unique, 'is_active' => true]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Multi Year ' . $this->unique,
            'phone'          => '081299990023',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        // Year 2026 cycle created
        $cycle2026 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        // Explicit creation for year 2027 by HR
        $resp = $this->actingAs($this->hrStaff1)->post(route('hr.follow-up.store'), [
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2027,
        ]);
        $resp->assertRedirect();

        $cycle2027 = FollowUpCycle::where('institution_contact_id', $contact->id)
            ->where('cycle_year', 2027)
            ->first();

        $this->assertNotNull($cycle2027);
        $this->assertEquals($this->hrStaff1->id, $cycle2027->confirmed_by);
        $this->assertNotNull($cycle2027->confirmed_at);

        // Exactly 2 distinct cycles exist for this contact
        $this->assertEquals(2, FollowUpCycle::where('institution_contact_id', $contact->id)->count());
    }

    public function test_linked_interns_context_in_cycle_show_view(): void
    {
        $city = City::create(['name' => 'Kota View ' . $this->unique]);
        $division = Division::create(['name' => 'Divisi View ' . $this->unique]);
        $institution = Institution::create(['name' => 'Kampus Show View ' . $this->unique, 'is_active' => true]);
        $faculty = Faculty::create(['institution_id' => $institution->id, 'name' => 'Fakultas View ' . $this->unique]);
        $studyProgram = StudyProgram::create(['faculty_id' => $faculty->id, 'name' => 'Prodi View ' . $this->unique]);

        $contact = InstitutionContact::create([
            'institution_id'   => $institution->id,
            'name'             => 'Dosen Pembimbing Context ' . $this->unique,
            'normalized_name'  => 'dosen pembimbing context',
            'phone'            => '081299990024',
            'normalized_phone' => '6281299990024',
            'is_active'        => true,
            'is_verified'      => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $user = User::create([
            'name'     => 'intern_view_' . substr($this->unique, 0, 8),
            'email'    => 'intern_view_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'user',
        ]);

        $registration = InternshipRegistration::create([
            'user_id'                  => $user->id,
            'fullname'                 => 'Pemagang Context Test',
            'email'                    => $user->email,
            'phone_number'             => '089999999999',
            'student_id'               => '99988877',
            'gender'                   => 'Laki-laki',
            'born_date'                => '2004-05-05',
            'current_status'           => 'Mahasiswa/Pelajar',
            'internship_type'          => 'Magang Mandiri',
            'institution_name'         => $institution->name,
            'institution_id'           => $institution->id,
            'faculty'                  => $faculty->name,
            'faculty_id'               => $faculty->id,
            'study_program'            => $studyProgram->name,
            'study_program_id'         => $studyProgram->id,
            'current_city'             => $city->name,
            'current_city_id'          => $city->id,
            'internship_reason'        => 'Magang Mandiri',
            'internship_interest'      => $division->name,
            'division_id'              => $division->id,
            'supervisor_name'          => 'Dosen Pembimbing Context ' . $this->unique,
            'supervisor_contact'       => '081299990024',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
            'internship_status'        => 'new',
        ]);

        // Link registration to contact with relation_year 2026
        $contact->internshipRegistrations()->attach($registration->id, [
            'public_uuid'   => (string) \Illuminate\Support\Str::uuid(),
            'relation_year' => 2026,
            'is_primary'    => true,
            'source'        => 'registration_auto',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $resp = $this->actingAs($this->hrStaff1)->get(route('hr.follow-up.show', $cycle));
        $resp->assertOk();
        $resp->assertSee('Pemagang Context Test');
        $resp->assertSee('Tahun 2026');
        $resp->assertSee($division->name);
    }
}
