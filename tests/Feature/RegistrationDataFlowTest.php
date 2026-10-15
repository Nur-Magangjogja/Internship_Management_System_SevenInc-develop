<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\RegistrationCustomAnswer;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FormBuilderService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationDataFlowTest extends TestCase
{
    protected array $cleanupUserIds = [];
    protected array $cleanupRegistrationIds = [];

    protected function tearDown(): void
    {
        \Mockery::close();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();

        if (!empty($this->cleanupRegistrationIds)) {
            RegistrationCustomAnswer::whereIn('registration_id', $this->cleanupRegistrationIds)->delete();
            IR::whereIn('id', $this->cleanupRegistrationIds)->forceDelete();
        }

        if (!empty($this->cleanupUserIds)) {
            User::whereIn('id', $this->cleanupUserIds)->forceDelete();
        }

        parent::tearDown();
    }

    protected function setUpMasterData(): array
    {
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);
        $inst = Institution::firstOrCreate(
            ['name' => 'Universitas Gadjah Mada', 'normalized_name' => 'universitas gadjah mada'],
            ['is_active' => true]
        );
        $fac  = Faculty::firstOrCreate(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $prog = StudyProgram::firstOrCreate(['name' => 'Teknologi Informasi', 'faculty_id' => $fac->id]);
        $div  = Division::firstOrCreate(
            ['name' => 'Administration'],
            ['slug' => 'administration', 'is_active' => true]
        );

        return compact('city', 'inst', 'fac', 'prog', 'div');
    }

    protected function createTestUser(string $role = 'user'): User
    {
        $unique = uniqid('test_', true);
        $user = User::create([
            'name'     => 'User_' . $unique,
            'email'    => 'user_' . $unique . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => $role,
        ]);

        $this->cleanupUserIds[] = $user->id;
        return $user;
    }

    protected function createTestAdmin(): User
    {
        return $this->createTestUser('admin');
    }

    protected function createTestRegistration(User $user, array $overrides = []): IR
    {
        $masters = $this->setUpMasterData();

        $reg = IR::create(array_merge([
            'user_id'                 => $user->id,
            'fullname'                => 'Peserta Uji',
            'student_id'              => '12345678',
            'born_date'               => '2002-05-10',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'institution_id'          => $masters['inst']->id,
            'faculty_id'              => $masters['fac']->id,
            'study_program_id'        => $masters['prog']->id,
            'city_id'                 => $masters['city']->id,
            'division_id'             => $masters['div']->id,
            'email'                   => $user->email,
            'internship_interest'     => $masters['div']->name,
            'internship_type'         => 'Magang Mandiri',
            'internship_arrangement'  => 'WFO',
            'current_status'          => 'Mahasiswa Aktif',
            'wfo_readiness'           => 'Siap',
            'schedule_shift_readiness'=> 'Siap',
            'has_laptop'              => true,
            'planned_duration'        => '3 Bulan',
            'internship_status'       => IR::STATUS_WAITING,
            'birth_place'             => 'Semarang',
            'religion'                => 'Islam',
        ], $overrides));

        $this->cleanupRegistrationIds[] = $reg->id;
        return $reg;
    }

    protected function getValidSubmissionPayload(array $overrides = []): array
    {
        $masters = $this->setUpMasterData();
        $unique = uniqid('reg_', true);

        return array_merge([
            'fullname'                 => 'Pendaftar ' . $unique,
            'gender'                   => 'Laki-laki',
            'current_city'             => $masters['city']->name,
            'email'                    => 'pendaftar_' . $unique . '@example.com',
            'phone_number'             => '081234567890',
            'current_status'           => 'Mahasiswa / Pelajar Aktif',
            'student_id'               => 'NIM' . rand(100000, 999999),
            'institution_name'         => $masters['inst']->name,
            'faculty'                  => $masters['fac']->name,
            'study_program'            => $masters['prog']->name,
            'internship_interest'      => $masters['div']->name,
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Ingin mengasah kemampuan dan berkontribusi.',
            'wfo_readiness'            => 'Siap WFO',
            'schedule_shift_readiness' => 'Bersedia',
            'has_laptop'               => '1',
            'planned_duration'         => '3 Bulan',
            'birth_place'              => 'Sleman',
            'religion'                 => 'Islam',
        ], $overrides);
    }

    /**
     * 1. Memverifikasi penyimpanan birth_place dan religion melalui pendaftaran resmi modern.
     */
    public function test_user_can_submit_registration_with_birth_place_and_religion(): void
    {
        $user = $this->createTestUser();
        $payload = $this->getValidSubmissionPayload([
            'birth_place' => 'Kota Yogyakarta',
            'religion'    => 'Islam',
        ]);

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionHasNoErrors();

        $registration = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($registration, 'Registration record should be created.');
        $this->cleanupRegistrationIds[] = $registration->id;

        $this->assertEquals('Kota Yogyakarta', $registration->birth_place);
        $this->assertEquals('Islam', $registration->religion);
    }

    /**
     * 2. Memverifikasi penyimpanan draft dengan birth_place dan religion opsional (boleh kosong / null).
     */
    public function test_user_can_save_draft_with_null_or_empty_birth_place_and_religion(): void
    {
        $masters = $this->setUpMasterData();
        $user = $this->createTestUser();
        $payload = [
            'fullname'         => 'Draft User',
            'institution_name' => $masters['inst']->name,
            'faculty'          => $masters['fac']->name,
            'study_program'    => $masters['prog']->name,
            'birth_place'      => '',
            'religion'         => '',
        ];

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.draft'), $payload);

        $response->assertSessionHasNoErrors();

        $registration = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($registration, 'Draft record should be created.');
        $this->cleanupRegistrationIds[] = $registration->id;

        $this->assertNull($registration->birth_place);
        $this->assertNull($registration->religion);
    }

    /**
     * 3. Memverifikasi penolakan validasi jika birth_place melebihi batas 100 karakter.
     */
    public function test_validation_rejects_birth_place_exceeding_100_characters(): void
    {
        $user = $this->createTestUser();
        $longBirthPlace = str_repeat('A', 101);
        $payload = $this->getValidSubmissionPayload([
            'birth_place' => $longBirthPlace,
        ]);

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionHasErrors(['birth_place']);
    }

    /**
     * 4. Memverifikasi penolakan validasi jika agama tidak terdaftar pada daftar yang disepakati.
     */
    public function test_validation_rejects_invalid_religion_value(): void
    {
        $user = $this->createTestUser();
        $payload = $this->getValidSubmissionPayload([
            'religion' => 'AgamaTidakValid123',
        ]);

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionHasErrors(['religion']);
    }

    /**
     * 5. Memverifikasi bahwa birth_place dan religion terdaftar di FormBuilder CORE_FIELD_KEYS
     *    dan tidak terjadi duplikasi penyimpanan ke registration_custom_answers.
     */
    public function test_core_fields_not_duplicated_in_custom_answers(): void
    {
        $this->assertContains('birth_place', FormBuilderService::CORE_FIELD_KEYS);
        $this->assertContains('religion', FormBuilderService::CORE_FIELD_KEYS);

        $user = $this->createTestUser();
        $payload = $this->getValidSubmissionPayload([
            'birth_place' => 'Bantul',
            'religion'    => 'Katolik',
        ]);

        $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $registration = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($registration);
        $this->cleanupRegistrationIds[] = $registration->id;

        // Pastikan tidak ada duplikasi jawaban kustom pada field_key birth_place atau religion
        $customCount = RegistrationCustomAnswer::where('registration_id', $registration->id)
            ->whereIn('field_key', ['birth_place', 'religion'])
            ->count();

        $this->assertEquals(0, $customCount, 'birth_place and religion must NOT be saved into custom answers table.');
    }

    /**
     * 6. Memverifikasi admin yang berwenang dapat memperbarui birth_place dan religion via endpoint update.
     */
    public function test_authorized_admin_can_update_intern_birth_place_and_religion(): void
    {
        $admin = $this->createTestAdmin();
        $user = $this->createTestUser();
        $registration = $this->createTestRegistration($user, [
            'birth_place' => 'Semarang',
            'religion'    => 'Islam',
        ]);

        $response = $this->actingAs($admin)
            ->patch(route('admin.interns.update', $registration->id), [
                'fullname'    => 'Peserta Uji Updated',
                'student_id'  => '12345678',
                'email'       => $user->email,
                'birth_place' => 'Surakarta',
                'religion'    => 'Kristen Protestan',
            ]);

        $response->assertSessionHasNoErrors();

        $registration->refresh();
        $this->assertEquals('Surakarta', $registration->birth_place);
        $this->assertEquals('Kristen Protestan', $registration->religion);
    }

    /**
     * 7. Memverifikasi penolakan akses pembaruan data intern oleh user biasa (tanpa role admin).
     */
    public function test_unauthorized_user_cannot_update_intern_data(): void
    {
        $user = $this->createTestUser();
        $otherUser = $this->createTestUser();
        $registration = $this->createTestRegistration($otherUser, [
            'birth_place' => 'Jakarta',
            'religion'    => 'Islam',
        ]);

        // Request via JSON expects 403 Forbidden
        $response = $this->actingAs($user)
            ->patchJson(route('admin.interns.update', $registration->id), [
                'fullname'    => 'Hacked Name',
                'student_id'  => '87654321',
                'email'       => $otherUser->email,
                'birth_place' => 'Hacked City',
                'religion'    => 'Hindu',
            ]);

        $response->assertStatus(403);

        $registration->refresh();
        $this->assertEquals('Jakarta', $registration->birth_place);
        $this->assertEquals('Islam', $registration->religion);
    }

    /**
     * 8. Memverifikasi kompatibilitas record historis bernilai NULL terbaca dengan benar pada respons API admin.
     */
    public function test_historical_registration_with_null_fields_remains_valid_and_readable(): void
    {
        $admin = $this->createTestAdmin();
        $user = $this->createTestUser();
        $registration = $this->createTestRegistration($user, [
            'student_id'  => 'HIST999',
            'birth_place' => null,
            'religion'    => null,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.interns.api', ['q' => 'HIST999']));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $matched = collect($data)->firstWhere('id', $registration->id);
        $this->assertNotNull($matched);
        $this->assertNull($matched['birth_place']);
        $this->assertNull($matched['religion']);
    }

    /**
     * 9. Memverifikasi pencarian Admin mencakup birth_place dan mengecualikan religion dari pencarian umum.
     */
    public function test_admin_search_includes_birth_place_when_column_exists_and_excludes_religion(): void
    {
        $admin = $this->createTestAdmin();
        $user = $this->createTestUser();
        $registration = $this->createTestRegistration($user, [
            'fullname'    => 'Searchable User',
            'student_id'  => 'SRCH888',
            'birth_place' => 'Magelang',
            'religion'    => 'Katolik',
        ]);

        // Pencarian berdasarkan birth_place harus menemukan data
        $resBirthPlace = $this->actingAs($admin)
            ->getJson(route('admin.interns.api', ['q' => 'Magelang']));
        $resBirthPlace->assertOk();
        $dataBirthPlace = $resBirthPlace->json('data');
        $this->assertNotEmpty($dataBirthPlace);
        $this->assertNotNull(collect($dataBirthPlace)->firstWhere('id', $registration->id));

        // Pencarian umum berdasarkan kata kunci agama ("Katolik") tidak boleh mengembalikan data
        // karena religion tidak didaftarkan di $searchable demi privasi data sensitif
        $resReligion = $this->actingAs($admin)
            ->getJson(route('admin.interns.api', ['q' => 'Katolik']));
        $resReligion->assertOk();
        $dataReligion = $resReligion->json('data');
        $matched = collect($dataReligion)->firstWhere('id', $registration->id);
        $this->assertNull($matched, 'Religion keyword must NOT match in general search.');
    }

    /**
     * 10. Memverifikasi pencarian Admin tidak error (200 OK) ketika kolom query dijalankan.
     */
    public function test_admin_search_succeeds_without_sql_errors(): void
    {
        $admin = $this->createTestAdmin();
        $response = $this->actingAs($admin)
            ->getJson(route('admin.interns.api', ['q' => 'NonExistentCityXYZ']));

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    /**
     * 11. Memverifikasi pencegahan silent data loss: jika schema unmigrated,
     * pengiriman field baru ditolak dengan error validasi dan tidak dibuang diam-diam.
     */
    public function test_submitting_new_fields_rejected_when_schema_unmigrated_to_prevent_silent_data_loss(): void
    {
        $originalSchema = Schema::getFacadeRoot();
        $mock = \Mockery::mock($originalSchema)->makePartial();
        $mock->shouldReceive('hasColumn')
            ->andReturnUsing(function ($table, $column) use ($originalSchema) {
                if ($table === 'internship_registrations' && in_array($column, ['birth_place', 'religion'], true)) {
                    return false;
                }
                return $originalSchema->hasColumn($table, $column);
            });
        Schema::swap($mock);

        try {
            $user = $this->createTestUser();

            // 1. Submit dengan nilai birth_place -> HARUS ditolak dengan error, BUKAN dibuang diam-diam
            $payloadWithBirthPlace = $this->getValidSubmissionPayload([
                'email'       => $user->email,
                'birth_place' => 'Bandung',
                'religion'    => null,
            ]);

            $response = $this->actingAs($user)
                ->post(route('pemagang.registration.store'), $payloadWithBirthPlace);

            $response->assertSessionHasErrors(['birth_place']);

            // 2. Submit tanpa field baru -> HARUS berhasil menyimpan pendaftaran existing secara normal
            $payloadClean = $this->getValidSubmissionPayload([
                'email'       => $user->email,
                'birth_place' => null,
                'religion'    => null,
            ]);

            $responseClean = $this->actingAs($user)
                ->post(route('pemagang.registration.store'), $payloadClean);

            $responseClean->assertSessionHasNoErrors();
            $created = IR::where('user_id', $user->id)->first();
            $this->assertNotNull($created);
            $this->cleanupRegistrationIds[] = $created->id;
        } finally {
            Schema::swap($originalSchema);
        }
    }

    /**
     * 12. Memverifikasi activity log meredaksi perubahan agama untuk menjaga privasi.
     */
    public function test_activity_log_redacts_religion_to_protect_privacy(): void
    {
        $admin = $this->createTestAdmin();
        $user = $this->createTestUser();
        $registration = $this->createTestRegistration($user, [
            'fullname'    => 'Peserta Privacy',
            'birth_place' => 'Yogyakarta',
            'religion'    => 'Islam',
        ]);

        $response = $this->actingAs($admin)
            ->patch(route('admin.interns.update', $registration->id), [
                'fullname'    => 'Peserta Privacy Updated',
                'student_id'  => '12345678',
                'email'       => $user->email,
                'birth_place' => 'Solo',
                'religion'    => 'Katolik',
            ]);

        $response->assertSessionHasNoErrors();

        $log = ActivityLog::where('subject_type', (new IR)->getMorphClass())
            ->where('subject_id', $registration->id)
            ->where('action', 'admin_intern_registration_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Activity log entry must be recorded.');
        
        $before = $log->before_data;
        $after = $log->after_data;

        // Field non-sensitif (fullname, birth_place) mencatat nilai sebelum & sesudah secara normal
        $this->assertEquals('Solo', $after['birth_place'] ?? null);

        // Field sensitif agama (religion) direduksi menjadi [DIUBAH], nilai mentah 'Islam' / 'Katolik' tidak tersimpan di log
        $this->assertEquals('[DIUBAH]', $before['religion'] ?? null);
        $this->assertEquals('[DIUBAH]', $after['religion'] ?? null);
    }
}
