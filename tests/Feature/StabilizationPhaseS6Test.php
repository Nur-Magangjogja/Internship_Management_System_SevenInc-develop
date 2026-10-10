<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Institution;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\InstitutionContact;
use App\Models\RegistrationContactLink;
use App\Models\Division;
use App\Models\City;
use App\Models\InternshipRegistration as IR;
use App\Services\ContactMatchingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StabilizationPhaseS6Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role = 'pemagang', array $attributes = []): User
    {
        $unique = uniqid('s6_test_', true);
        return User::create(array_merge([
            'name'      => 'usr_' . Str::random(12),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ], $attributes));
    }

    protected function createValidRegistrationPayload(User $user, Institution $institution, Division $division, array $overrides = []): array
    {
        return array_merge([
            'fullname'                 => 'Peserta Magang S6 ' . Str::random(4),
            'email'                    => $user->email,
            'phone_number'             => '08123456' . rand(1000, 9999),
            'student_id'               => 'NIM_' . rand(10000, 99999),
            'gender'                   => 'Laki-laki',
            'born_date'                => '2001-01-01',
            'current_status'           => 'Mahasiswa/Pelajar',
            'internship_type'          => 'Magang Mandiri',
            'institution_name'         => $institution->name,
            'faculty'                  => 'Fakultas Teknik',
            'study_program'            => 'Informatika',
            'current_city'             => 'Yogyakarta',
            'internship_reason'        => 'Magang Mandiri',
            'internship_interest'      => $division->name,
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',
        ], $overrides);
    }

    /**
     * 1. Helper normalizeName membersihkan gelar akademis, kehormatan, dan tanda baca umum.
     */
    public function test_contact_name_normalization_strips_common_titles_and_punctuation(): void
    {
        $this->assertEquals('ahmad dahlan', InstitutionContact::normalizeName('Prof. Dr. Ir. H. Ahmad Dahlan, M.Kom'));
        $this->assertEquals('siti fatimah', InstitutionContact::normalizeName('Dra. Hj. Siti Fatimah, M.Pd'));
        $this->assertEquals('budi santoso', InstitutionContact::normalizeName('Pak Budi Santoso, S.T.'));
        $this->assertEquals('wahyudi', InstitutionContact::normalizeName('Bpk. Wahyudi'));
        $this->assertEquals('628123456789', InstitutionContact::normalizePhone('0812-3456-789'));
    }

    /**
     * 2. Exact unique phone + name verified active -> auto-link berhasil.
     */
    public function test_exact_unique_phone_and_name_verified_active_auto_links(): void
    {
        $institution = Institution::create(['name' => 'Universitas AutoLink S6 ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-1']);

        $existingContact = InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Ir. Bambang Sudarsono, M.Sc',
            'phone'           => '081234567800',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        $initialContactCount = InstitutionContact::where('institution_id', $institution->id)->count();

        $user = $this->makeUser('user');
        $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
            'supervisor_name'    => 'Prof. Bambang Sudarsono',
            'supervisor_contact' => '081234567800',
        ]);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $response->assertSessionDoesntHaveErrors();

        // Jumlah kontak kanonikal tidak bertambah
        $this->assertEquals($initialContactCount, InstitutionContact::where('institution_id', $institution->id)->count());

        $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();
        $link = RegistrationContactLink::where('internship_registration_id', $reg->id)->firstOrFail();

        $this->assertEquals($existingContact->id, $link->institution_contact_id);
        $this->assertEquals('system_auto_link', $link->source);
        $this->assertNull($link->confirmed_by, 'System auto-link tidak boleh memalsukan user penjamin');
        $this->assertNull($link->confirmed_at);
    }

    /**
     * 3. Same phone + different name -> no auto-link, masuk antrean review.
     */
    public function test_same_phone_different_name_does_not_auto_link(): void
    {
        $institution = Institution::create(['name' => 'Universitas Name Mismatch ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-2']);

        $existingContact = InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Ir. Bambang Sudarsono, M.Sc',
            'phone'           => '081234567800',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        $user = $this->makeUser('user');
        $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
            'supervisor_name'    => 'Dr. Siti Rahmawati', // Nama berbeda drastis meskipun nomor telepon sama
            'supervisor_contact' => '081234567800',
        ]);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();

        // Tidak boleh terhubung otomatis karena potensi nomor bersama/salah nama
        $this->assertNull(RegistrationContactLink::where('internship_registration_id', $reg->id)->first());

        // Masuk antrean review HR/Admin
        $queueIds = ContactMatchingService::getReviewQueueQuery()->pluck('id')->all();
        $this->assertContains($reg->id, $queueIds);
    }

    /**
     * 4. Duplicate canonical phone (>1 contact sama telepon) -> no auto-link, masuk review.
     */
    public function test_duplicate_canonical_phone_does_not_auto_link(): void
    {
        $institution = Institution::create(['name' => 'Universitas Dup Phone ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-3']);

        // Dua kontak berbeda di institusi sama memiliki nomor telepon kantor yang sama persis
        InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Dosen A',
            'phone'           => '081299998888',
            'is_verified'     => true,
            'is_active'       => true,
        ]);
        InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Dosen B',
            'phone'           => '081299998888',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        $user = $this->makeUser('user');
        $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
            'supervisor_name'    => 'Dosen A',
            'supervisor_contact' => '081299998888',
        ]);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();

        // Tidak boleh auto-link karena ambiguous match
        $this->assertNull(RegistrationContactLink::where('internship_registration_id', $reg->id)->first());
        $this->assertContains($reg->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());
    }

    /**
     * 5. Inactive contact -> no auto-link, masuk review.
     */
    public function test_inactive_contact_does_not_auto_link(): void
    {
        $institution = Institution::create(['name' => 'Universitas Inactive ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-4']);

        InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Pak Budi Nonaktif',
            'phone'           => '081255554444',
            'is_verified'     => true,
            'is_active'       => false, // NON-AKTIF
        ]);

        $user = $this->makeUser('user');
        $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
            'supervisor_name'    => 'Budi Nonaktif',
            'supervisor_contact' => '081255554444',
        ]);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertNull(RegistrationContactLink::where('internship_registration_id', $reg->id)->first());
        $this->assertContains($reg->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());
    }

    /**
     * 6. Unverified contact -> no auto-link, masuk review.
     */
    public function test_unverified_contact_does_not_auto_link(): void
    {
        $institution = Institution::create(['name' => 'Universitas Unverified ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-5']);

        InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Calon Pembimbing Menunggu',
            'phone'           => '081277776666',
            'is_verified'     => false, // BELUM TERVERIFIKASI
            'is_active'       => true,
        ]);

        $user = $this->makeUser('user');
        $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
            'supervisor_name'    => 'Calon Pembimbing Menunggu',
            'supervisor_contact' => '081277776666',
        ]);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertNull(RegistrationContactLink::where('internship_registration_id', $reg->id)->first());
        $this->assertContains($reg->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());
    }

    /**
     * 7. Name-only match without usable phone -> no auto-link, masuk review.
     */
    public function test_name_only_match_without_phone_does_not_auto_link(): void
    {
        $institution = Institution::create(['name' => 'Universitas NameOnly ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-6']);

        InstitutionContact::create([
            'institution_id'  => $institution->id,
            'name'            => 'Drs. Suparman',
            'phone'           => '081211112222',
            'is_verified'     => true,
            'is_active'       => true,
        ]);

        $user = $this->makeUser('user');
        $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
            'supervisor_name'    => 'Suparman',
            'supervisor_contact' => '', // Tanpa nomor telepon
        ]);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $response->assertSessionDoesntHaveErrors();

        $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();
        // Tidak auto-link hanya karena nama sama
        $this->assertNull(RegistrationContactLink::where('internship_registration_id', $reg->id)->first());
        $this->assertContains($reg->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());
    }

    /**
     * 8. Applicant registration NEVER creates canonical contact in institution_contacts.
     */
    public function test_applicant_cannot_create_canonical_contact(): void
    {
        $institution = Institution::create(['name' => 'Institut Bebas Contact ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-7']);

        $initialContactCount = InstitutionContact::count();

        $user = $this->makeUser('user');
        $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
            'supervisor_name'    => 'Guru Baru Belum Terdaftar ' . Str::random(4),
            'supervisor_contact' => '089988776655',
        ]);

        $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
        $response->assertSessionDoesntHaveErrors();

        // Verifikasi tidak ada baris baru di tabel institution_contacts
        $this->assertEquals($initialContactCount, InstitutionContact::count(), 'Applicant tidak boleh membuat contact di database!');

        $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertNotEmpty($reg->supervisor_name);
        $this->assertNotEmpty($reg->supervisor_contact);
        $this->assertNull(RegistrationContactLink::where('internship_registration_id', $reg->id)->first());
        $this->assertContains($reg->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());
    }

    /**
     * 9. Placeholder supervisor creates neither contact nor queue candidate nor link.
     */
    public function test_placeholder_creates_neither_contact_nor_queue_candidate_nor_link(): void
    {
        $institution = Institution::create(['name' => 'Institut Placeholder Test ' . Str::random(5)]);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-8']);

        $initialContactCount = InstitutionContact::count();

        $placeholders = [
            ['name' => 'Belum Dapat Dosen Pembimbing', 'phone' => '-'],
            ['name' => 'Belum ada', 'phone' => ''],
            ['name' => 'Tidak ada', 'phone' => '-'],
            ['name' => '-', 'phone' => '-'],
            ['name' => '', 'phone' => ''],
        ];

        foreach ($placeholders as $item) {
            $user = $this->makeUser('user');
            $payload = $this->createValidRegistrationPayload($user, $institution, $division, [
                'supervisor_name'    => $item['name'],
                'supervisor_contact' => $item['phone'],
            ]);

            $response = $this->actingAs($user)->post(route('pemagang.registration.store'), $payload);
            $response->assertSessionDoesntHaveErrors();

            $reg = IR::where('user_id', $user->id)->latest('id')->firstOrFail();

            // Tidak membuat link
            $this->assertNull(RegistrationContactLink::where('internship_registration_id', $reg->id)->first());

            // Tidak mengotori review queue
            $queueIds = ContactMatchingService::getReviewQueueQuery()->pluck('id')->all();
            $this->assertNotContains($reg->id, $queueIds, "Placeholder '{$item['name']}' tidak boleh masuk review queue!");
        }

        $this->assertEquals($initialContactCount, InstitutionContact::count());
    }

    /**
     * 10. HR Staff can resolve review queue: link existing or create & verify new.
     */
    public function test_hr_can_resolve_review_queue(): void
    {
        $hrStaff = $this->makeUser('hr_staff');
        $institution = Institution::create(['name' => 'Kampus HR Review ' . Str::random(5)]);
        $fac  = Faculty::create(['name' => 'Fakultas Teknik S6', 'institution_id' => $institution->id]);
        $prog = StudyProgram::create(['name' => 'Teknik Informatika S6', 'faculty_id' => $fac->id]);
        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-9']);

        $user1 = $this->makeUser('user');
        $reg1 = IR::create(array_merge(
            $this->createValidRegistrationPayload($user1, $institution, $division, [
                'supervisor_name'    => 'Bpk Hendro',
                'supervisor_contact' => '081233334444',
            ]),
            [
                'user_id'           => $user1->id,
                'institution_id'    => $institution->id,
                'faculty_id'        => $fac->id,
                'study_program_id'  => $prog->id,
                'city_id'           => $city->id,
                'division_id'       => $division->id,
                'internship_status' => IR::STATUS_WAITING,
            ]
        ));

        // 1. HR melihat antrean review
        $response = $this->actingAs($hrStaff)->get(route('hr.contact-review.index'));
        $response->assertStatus(200);
        $response->assertSee('Antrean Review Kontak Pembimbing');

        // 2. HR menautkan ke kontak kanonikal yang sudah ada
        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Drs. Hendro Wibowo, M.Pd',
            'phone'          => '081233334444',
            'is_verified'    => true,
            'is_active'      => true,
        ]);

        $linkResponse = $this->actingAs($hrStaff)->post(route('hr.contact-review.link', $reg1), [
            'institution_contact_id' => $contact->id,
        ]);
        $linkResponse->assertRedirect();
        $linkResponse->assertSessionHas('success');

        $link = RegistrationContactLink::where('internship_registration_id', $reg1->id)->firstOrFail();
        $this->assertEquals($contact->id, $link->institution_contact_id);
        $this->assertEquals($hrStaff->id, $link->confirmed_by);
        $this->assertNotNull($link->confirmed_at);

        // Raw supervisor tetap utuh setelah linkExisting
        $reg1->refresh();
        $this->assertEquals('Bpk Hendro', $reg1->supervisor_name);
        $this->assertEquals('081233334444', $reg1->supervisor_contact);

        // Setelah ditautkan, reg1 keluar dari antrean review karena valid link tercipta
        $this->assertNotContains($reg1->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());

        // 3. HR membuat dan memverifikasi kontak kanonikal baru untuk pendaftaran kedua
        $user2 = $this->makeUser('user');
        $reg2 = IR::create(array_merge(
            $this->createValidRegistrationPayload($user2, $institution, $division, [
                'supervisor_name'    => 'Ibu Wati',
                'supervisor_contact' => '081299991111',
            ]),
            [
                'user_id'           => $user2->id,
                'institution_id'    => $institution->id,
                'faculty_id'        => $fac->id,
                'study_program_id'  => $prog->id,
                'city_id'           => $city->id,
                'division_id'       => $division->id,
                'internship_status' => IR::STATUS_WAITING,
            ]
        ));

        $createResponse = $this->actingAs($hrStaff)->post(route('hr.contact-review.create-and-verify', $reg2), [
            'name'           => 'Dr. Rahmawati Kusuma, S.T., M.Eng',
            'position_title' => 'Koordinator Magang & Kerjasama',
            'phone'          => '081299991111',
            'email'          => 'rahmawati@kampus.ac.id',
            'notes'          => 'Diverifikasi langsung oleh HR',
        ]);
        $createResponse->assertRedirect();
        $createResponse->assertSessionHas('success');

        $newContact = InstitutionContact::where('email', 'rahmawati@kampus.ac.id')->firstOrFail();
        $this->assertTrue((bool) $newContact->is_verified);
        $this->assertEquals($hrStaff->id, $newContact->verified_by);
        $this->assertEquals('Koordinator Magang & Kerjasama', $newContact->position_title);

        $link2 = RegistrationContactLink::where('internship_registration_id', $reg2->id)->firstOrFail();
        $this->assertEquals($newContact->id, $link2->institution_contact_id);
        $this->assertEquals($hrStaff->id, $link2->confirmed_by);

        // Raw supervisor tetap utuh setelah createAndVerify
        $reg2->refresh();
        $this->assertEquals('Ibu Wati', $reg2->supervisor_name);
        $this->assertEquals('081299991111', $reg2->supervisor_contact);

        // Reg2 keluar dari antrean karena valid link tercipta
        $this->assertNotContains($reg2->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());
    }

    /**
     * 11. Admin can resolve same review queue using shared logic.
     */
    public function test_admin_can_resolve_same_review_queue(): void
    {
        $admin = $this->makeUser('admin');
        $institution = Institution::create(['name' => 'Kampus Admin Review ' . Str::random(5)]);
        $fac  = Faculty::create(['name' => 'Fakultas Teknik S6 Admin', 'institution_id' => $institution->id]);
        $prog = StudyProgram::create(['name' => 'Teknik Informatika S6 Admin', 'faculty_id' => $fac->id]);
        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $division = Division::first() ?? Division::create(['name' => 'Programmer', 'slug' => 'prog-s6-10']);

        $user = $this->makeUser('user');
        $reg = IR::create(array_merge(
            $this->createValidRegistrationPayload($user, $institution, $division, [
                'supervisor_name'    => 'Bpk Anwar',
                'supervisor_contact' => '081288887777',
            ]),
            [
                'user_id'           => $user->id,
                'institution_id'    => $institution->id,
                'faculty_id'        => $fac->id,
                'study_program_id'  => $prog->id,
                'city_id'           => $city->id,
                'division_id'       => $division->id,
                'internship_status' => IR::STATUS_WAITING,
            ]
        ));

        // Admin mengakses antrean review admin
        $response = $this->actingAs($admin)->get(route('admin.contact-review.index'));
        $response->assertStatus(200);

        // Admin membuat dan memverifikasi kontak
        $createResponse = $this->actingAs($admin)->post(route('admin.contact-review.create-and-verify', $reg), [
            'name'           => 'Prof. Dr. Anwar Sanusi',
            'position_title' => 'Dekan Fakultas Teknik',
            'phone'          => '081288887777',
            'email'          => 'anwar@fakultas.ac.id',
        ]);
        $createResponse->assertRedirect();
        $createResponse->assertSessionHas('success');

        $contact = InstitutionContact::where('email', 'anwar@fakultas.ac.id')->firstOrFail();
        $this->assertTrue((bool) $contact->is_verified);
        $this->assertEquals($admin->id, $contact->verified_by);

        $link = RegistrationContactLink::where('internship_registration_id', $reg->id)->firstOrFail();
        $this->assertEquals($contact->id, $link->institution_contact_id);
        $this->assertEquals($admin->id, $link->confirmed_by);

        // Raw supervisor tetap utuh setelah aksi Admin
        $reg->refresh();
        $this->assertEquals('Bpk Anwar', $reg->supervisor_name);
        $this->assertEquals('081288887777', $reg->supervisor_contact);
        $this->assertNotContains($reg->id, ContactMatchingService::getReviewQueueQuery()->pluck('id')->all());
    }

    /**
     * 12. DB default is_verified is 0 (false) in database schema.
     */
    public function test_db_default_is_verified_is_zero(): void
    {
        $columns = DB::select("SHOW COLUMNS FROM institution_contacts WHERE Field = 'is_verified'");
        $this->assertNotEmpty($columns);
        $this->assertEquals('0', (string) $columns[0]->Default, "DB default is_verified harus 0");

        // Memastikan kontak baru tanpa eksplisit is_verified bernilai false
        $institution = Institution::create(['name' => 'Kampus Default Check ' . Str::random(5)]);
        $contactId = DB::table('institution_contacts')->insertGetId([
            'public_uuid'    => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'name'           => 'Kontak Tanpa Explicit Verified',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $rawRow = DB::table('institution_contacts')->where('id', $contactId)->first();
        $this->assertEquals(0, (int) $rawRow->is_verified);
    }

    /**
     * 13. Tidak ada route dismiss untuk Admin maupun HR (mencegah mutasi destruktif pada data mentah pelamar).
     */
    public function test_no_dismiss_routes_exist_for_admin_or_hr(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.contact-review.dismiss'), 'Route admin.contact-review.dismiss tidak boleh ada');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('hr.contact-review.dismiss'), 'Route hr.contact-review.dismiss tidak boleh ada');
    }
}
