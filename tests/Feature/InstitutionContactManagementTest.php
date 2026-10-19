<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Division;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\RegistrationContactLink;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InstitutionContactManagementTest extends TestCase
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

        $this->unique = uniqid('cnt_', true);

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

    public function test_admin_can_view_contacts_on_institution_detail_page(): void
    {
        $institution = Institution::create(['name' => 'Kampus Uji ' . $this->unique]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dr. Hendra Gunawan ' . $this->unique,
            'position_title' => 'Dosen Pembimbing Lapangan',
            'phone'          => '081299990001',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.institutions.show', $institution));

        $response->assertStatus(200);
        $response->assertSee('Dr. Hendra Gunawan ' . $this->unique);
        $response->assertSee('Dosen Pembimbing Lapangan');
        $response->assertSee('081299990001');
    }

    public function test_admin_can_add_institution_contact_and_log_is_recorded(): void
    {
        $institution = Institution::create(['name' => 'Kampus Kontak ' . $this->unique]);

        $payload = [
            'name'           => 'Drs. Supriyanto, M.Pd ' . $this->unique,
            'position_title' => 'Ketua BKK SMK',
            'phone'          => '081322334455',
            'email'          => 'bkk@smk.sch.id',
            'notes'          => 'Kontak utama koordinasi penempatan magang',
        ];

        $response = $this->actingAs($this->admin)->post(
            route('admin.institutions.contacts.store', $institution),
            $payload
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('institution_contacts', [
            'institution_id'   => $institution->id,
            'name'             => 'Drs. Supriyanto, M.Pd ' . $this->unique,
            'normalized_phone' => '6281322334455',
            'email'            => 'bkk@smk.sch.id',
            'is_active'        => 1,
        ]);

        $contact = InstitutionContact::where('name', 'Drs. Supriyanto, M.Pd ' . $this->unique)->first();
        $this->assertNotNull($contact->public_uuid);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'institution_contact_created',
            'actor_user_id' => $this->admin->id,
            'subject_type'  => InstitutionContact::class,
            'subject_id'    => $contact->id,
        ]);
    }

    public function test_cannot_add_duplicate_contact_in_same_institution(): void
    {
        $institution = Institution::create(['name' => 'Kampus Duplikat ' . $this->unique]);
        InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Ir. Wahyudi ' . $this->unique,
            'phone'          => '081234567890',
        ]);

        // Kirim dengan nama serupa (case insensitive & spaces)
        $response = $this->actingAs($this->admin)->post(
            route('admin.institutions.contacts.store', $institution),
            [
                'name'  => '  ir. wahyudi ' . $this->unique . '  ',
                'phone' => '089999999999',
            ]
        );

        $response->assertSessionHasErrors(['name']);
    }

    public function test_admin_can_update_institution_contact_and_log_is_recorded(): void
    {
        $institution = Institution::create(['name' => 'Kampus Update ' . $this->unique]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pak Budi ' . $this->unique,
            'phone'          => '081111111111',
            'position_title' => 'Guru',
        ]);

        $payload = [
            'name'           => 'Pak Budi Santoso, S.T ' . $this->unique,
            'phone'          => '082212345678',
            'position_title' => 'Kepala Program Keahlian',
            'email'          => 'budi@sekolah.sch.id',
            'notes'          => 'Catatan diperbarui',
        ];

        $response = $this->actingAs($this->admin)->put(
            route('admin.institution-contacts.update', $contact),
            $payload
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('institution_contacts', [
            'id'               => $contact->id,
            'name'             => 'Pak Budi Santoso, S.T ' . $this->unique,
            'normalized_phone' => '6282212345678',
            'position_title'   => 'Kepala Program Keahlian',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'institution_contact_updated',
            'subject_type' => InstitutionContact::class,
            'subject_id'   => $contact->id,
        ]);
    }

    public function test_admin_can_toggle_institution_contact_status(): void
    {
        $institution = Institution::create(['name' => 'Kampus Toggle ' . $this->unique]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Aktif ' . $this->unique,
            'is_active'      => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.institution-contacts.toggle-status', $contact));

        $response->assertRedirect();
        $contact->refresh();
        $this->assertFalse($contact->is_active);

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'institution_contact_status_toggled',
            'subject_type' => InstitutionContact::class,
            'subject_id'   => $contact->id,
        ]);
    }

    public function test_admin_can_soft_delete_institution_contact(): void
    {
        $institution = Institution::create(['name' => 'Kampus Delete ' . $this->unique]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Hapus ' . $this->unique,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.institution-contacts.destroy', $contact));

        $response->assertRedirect();
        $this->assertSoftDeleted('institution_contacts', ['id' => $contact->id]);

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'institution_contact_deleted',
            'subject_type' => InstitutionContact::class,
            'subject_id'   => $contact->id,
        ]);
    }

    public function test_hr_staff_can_add_and_update_institution_contacts(): void
    {
        $institution = Institution::create(['name' => 'Kampus HR Kontak ' . $this->unique]);

        $storeResponse = $this->actingAs($this->hrStaff)->post(
            route('hr.institutions.contacts.store', $institution),
            [
                'name'           => 'Bu Rini ' . $this->unique,
                'position_title' => 'Koordinator Magang',
                'phone'          => '081555556666',
            ]
        );

        $storeResponse->assertRedirect();
        $this->assertDatabaseHas('institution_contacts', [
            'institution_id' => $institution->id,
            'name'           => 'Bu Rini ' . $this->unique,
        ]);

        $contact = InstitutionContact::where('name', 'Bu Rini ' . $this->unique)->first();

        $updateResponse = $this->actingAs($this->hrStaff)->put(
            route('hr.institution-contacts.update', $contact),
            [
                'name'           => 'Bu Rini Handayani, M.Si ' . $this->unique,
                'position_title' => 'Koordinator Magang & Kerjasama',
                'phone'          => '081555556666',
            ]
        );

        $updateResponse->assertRedirect();
        $this->assertDatabaseHas('institution_contacts', [
            'id'   => $contact->id,
            'name' => 'Bu Rini Handayani, M.Si ' . $this->unique,
        ]);
    }

    public function test_pemagang_cannot_manage_institution_contacts(): void
    {
        $institution = Institution::create(['name' => 'Kampus Pemagang Akses ' . $this->unique]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Rahasia ' . $this->unique,
        ]);

        $res1 = $this->actingAs($this->pemagang)->post(route('admin.institutions.contacts.store', $institution), ['name' => 'Test']);
        $res1->assertRedirect(route('user.dashboard'));

        $res2 = $this->actingAs($this->pemagang)->delete(route('admin.institution-contacts.destroy', $contact));
        $res2->assertRedirect(route('user.dashboard'));
    }

    public function test_hr_staff_cannot_delete_institution_contact(): void
    {
        $institution = Institution::create(['name' => 'Kampus HR Protect ' . $this->unique]);
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Kontak Terlindungi ' . $this->unique,
        ]);

        // HR Staff mencoba mengirim request DELETE ke admin.institution-contacts.destroy
        $response = $this->actingAs($this->hrStaff)->delete(route('admin.institution-contacts.destroy', $contact));
        $this->assertTrue(in_array($response->status(), [403, 302], true));
        $this->assertDatabaseHas('institution_contacts', ['id' => $contact->id]);
    }

    public function test_registration_with_supervisor_creates_institution_contact_and_link(): void
    {
        $institution = Institution::create(['name' => 'Universitas Pembimbing ' . $this->unique]);
        $division = Division::first() ?? Division::create(['name' => 'Administration', 'slug' => 'adm-test']);

        // Kontak kanonikal resmi terdaftar di institusi
        $contact = InstitutionContact::create([
            'institution_id'   => $institution->id,
            'name'             => 'Prof. Dr. Ir. H. Ahmad Dahlan ' . $this->unique,
            'phone'            => '081234998877',
            'is_verified'      => true,
            'is_active'        => true,
        ]);

        $payload = [
            'fullname'            => 'Peserta Magang ' . $this->unique,
            'gender'              => 'Laki-laki',
            'born_date'           => '2002-05-15',
            'current_city'        => 'Yogyakarta',
            'email'               => $this->pemagang->email,
            'phone_number'        => '081234567890',
            'current_status'      => 'Mahasiswa/Pelajar',
            'student_id'          => '12345678',
            'institution_name'    => 'Universitas Pembimbing ' . $this->unique,
            'faculty'             => 'Teknik',
            'study_program'       => 'Informatika',
            'internship_interest' => $division->name,
            'internship_type'     => 'PKL',
            'internship_reason'   => 'Menambah pengalaman kerja.',
            'supervisor_name'     => 'Prof. Dr. Ir. H. Ahmad Dahlan ' . $this->unique,
            'supervisor_contact'  => '081234998877',
            'wfo_readiness'       => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'          => 1,
            'planned_duration'    => '3 Bulan',
        ];

        $response = $this->actingAs($this->pemagang)->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionDoesntHaveErrors();

        // 1. Kolom legacy di internship_registrations tetap tersimpan
        $this->assertDatabaseHas('internship_registrations', [
            'user_id'            => $this->pemagang->id,
            'supervisor_name'    => 'Prof. Dr. Ir. H. Ahmad Dahlan ' . $this->unique,
            'supervisor_contact' => '081234998877',
        ]);

        // 2. Kontak pembimbing kanonikal tetap ada
        $this->assertDatabaseHas('institution_contacts', [
            'id'               => $contact->id,
            'institution_id'   => $institution->id,
            'normalized_phone' => '6281234998877',
        ]);

        $registration = InternshipRegistration::where('user_id', $this->pemagang->id)->latest('id')->first();

        // 3. Link pivot dibuat di registration_contact_links via auto-link
        $this->assertDatabaseHas('registration_contact_links', [
            'internship_registration_id' => $registration->id,
            'institution_contact_id'     => $contact->id,
            'is_primary'                 => 1,
            'source'                     => 'system_auto_link',
        ]);

        // 4. Relasi Eloquent bekerja dua arah
        $this->assertTrue($registration->institutionContacts->contains($contact));
        $this->assertTrue($contact->internshipRegistrations->contains($registration));
    }
}
