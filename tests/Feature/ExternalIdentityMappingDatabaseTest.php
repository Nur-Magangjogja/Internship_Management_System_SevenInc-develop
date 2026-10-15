<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Division;
use App\Models\ExternalIdentityMapping;
use App\Models\ExternalRegistrationMapping;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature test untuk verifikasi integritas database nyata:
 * - external_identity_mappings
 * - external_registration_mappings
 * Sesuai kontrak Phase C.1B.
 */
class ExternalIdentityMappingDatabaseTest extends TestCase
{
    use DatabaseTransactions;

    protected array $cleanupUserIds = [];
    protected array $cleanupRegistrationIds = [];

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    protected function createTestUser(string $role = 'user'): User
    {
        $unique = uniqid('test_ext_', true);
        $user = User::create([
            'name'     => 'Ext User ' . $unique,
            'email'    => 'ext_' . $unique . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => $role,
        ]);

        $this->cleanupUserIds[] = $user->id;
        return $user;
    }

    protected function createTestRegistration(User $user, array $overrides = []): InternshipRegistration
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

        $reg = InternshipRegistration::create(array_merge([
            'user_id'                 => $user->id,
            'fullname'                => 'Peserta Integrasi ' . uniqid(),
            'student_id'              => '12345678',
            'born_date'               => '2002-05-10',
            'gender'                  => 'Laki-laki',
            'phone_number'            => '081234567890',
            'institution_id'          => $inst->id,
            'faculty_id'              => $fac->id,
            'study_program_id'        => $prog->id,
            'city_id'                 => $city->id,
            'division_id'             => $div->id,
            'email'                   => $user->email,
            'internship_interest'     => $div->name,
            'internship_type'         => 'Magang Mandiri',
            'internship_arrangement'  => 'WFO',
            'current_status'          => 'Mahasiswa Aktif',
            'wfo_readiness'           => 'Siap',
            'schedule_shift_readiness'=> 'Siap',
            'has_laptop'              => true,
            'planned_duration'        => '3 Bulan',
            'internship_status'       => InternshipRegistration::STATUS_ACCEPTED,
        ], $overrides));

        $this->cleanupRegistrationIds[] = $reg->id;
        return $reg;
    }

    /**
     * Kriteria 1: Pembuatan mapping identitas eksternal yang valid.
     */
    public function test_01_valid_external_identity_mapping_creation(): void
    {
        $user = $this->createTestUser();
        $admin = $this->createTestUser('admin');

        $identity = ExternalIdentityMapping::create([
            'source_system'       => ExternalIdentityMapping::SOURCE_ABSEN_DJURAGAN,
            'external_user_id'    => 'absen_usr_1001',
            'ims_user_id'         => $user->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
            'verified_at'         => now(),
            'verified_by'         => $admin->id,
        ]);

        $this->assertDatabaseHas('external_identity_mappings', [
            'id'                  => $identity->id,
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'absen_usr_1001',
            'ims_user_id'         => $user->id,
            'verification_status' => 'verified',
            'verified_by'         => $admin->id,
        ]);
        $this->assertTrue($identity->isVerified());
    }

    /**
     * Kriteria 2: Penolakan duplikasi source_system dan external_user_id.
     */
    public function test_02_rejection_of_duplicate_source_system_and_external_user_id(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'absen_usr_duplicate_test',
            'ims_user_id'         => $user1->id,
            'verification_status' => ExternalIdentityMapping::STATUS_PENDING_VERIFICATION,
        ]);

        $this->expectException(QueryException::class);

        // Duplikasi source_system & external_user_id harus ditolak oleh uk_ext_identity_source_user
        ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'absen_usr_duplicate_test',
            'ims_user_id'         => $user2->id,
            'verification_status' => ExternalIdentityMapping::STATUS_PENDING_VERIFICATION,
        ]);
    }

    /**
     * Kriteria 3: Penolakan duplikasi external_registration_id pada source yang sama.
     */
    public function test_03_rejection_of_duplicate_external_registration_id_on_same_source(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $reg1 = $this->createTestRegistration($user1);
        $reg2 = $this->createTestRegistration($user2);

        $identity1 = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'ext_u_1',
            'ims_user_id'         => $user1->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        $identity2 = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'ext_u_2',
            'ims_user_id'         => $user2->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity1->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'absen_reg_9999',
            'ims_registration_id'          => $reg1->id,
        ]);

        $this->expectException(QueryException::class);

        // Duplikasi external_registration_id pada source_system yang sama harus ditolak oleh uk_ext_reg_source_external
        ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity2->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'absen_reg_9999',
            'ims_registration_id'          => $reg2->id,
        ]);
    }

    /**
     * Kriteria 4: Penolakan mapping ganda untuk satu registrasi IMS pada source yang sama.
     */
    public function test_04_rejection_of_duplicate_mapping_for_same_ims_registration(): void
    {
        $user = $this->createTestUser();
        $reg = $this->createTestRegistration($user);

        $identity = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'ext_u_multi_attempt',
            'ims_user_id'         => $user->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'absen_reg_001',
            'ims_registration_id'          => $reg->id,
        ]);

        $this->expectException(QueryException::class);

        // Registrasi IMS yang sama tidak boleh dipetakan dua kali untuk source_system yang sama (uk_ext_reg_source_ims)
        ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'absen_reg_002',
            'ims_registration_id'          => $reg->id,
        ]);
    }

    /**
     * Kriteria 5: Satu external identity dapat mempunyai dua registrasi berbeda untuk dua periode.
     */
    public function test_05_multi_period_external_registrations_under_single_identity(): void
    {
        $user = $this->createTestUser();
        $regPeriod1 = $this->createTestRegistration($user, ['planned_duration' => '3 Bulan']);
        $regPeriod2 = $this->createTestRegistration($user, ['planned_duration' => '6 Bulan']);

        $identity = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'absen_user_multi_period',
            'ims_user_id'         => $user->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        $map1 = ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'absen_reg_period_1',
            'ims_registration_id'          => $regPeriod1->id,
        ]);

        $map2 = ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'absen_reg_period_2',
            'ims_registration_id'          => $regPeriod2->id,
        ]);

        $this->assertDatabaseHas('external_registration_mappings', ['id' => $map1->id]);
        $this->assertDatabaseHas('external_registration_mappings', ['id' => $map2->id]);

        $identity->refresh();
        $this->assertCount(2, $identity->registrationMappings);
        $this->assertCount(2, $identity->registrations);
        $this->assertTrue($identity->registrations->pluck('id')->contains($regPeriod1->id));
        $this->assertTrue($identity->registrations->pluck('id')->contains($regPeriod2->id));
    }

    /**
     * Kriteria 6: Foreign key menolak referensi user atau registrasi yang tidak tersedia.
     */
    public function test_06_foreign_key_rejects_non_existent_references(): void
    {
        // 6A: Foreign key ims_user_id non-existent
        try {
            ExternalIdentityMapping::create([
                'source_system'       => 'absen_djuragan',
                'external_user_id'    => 'ext_non_existent_user',
                'ims_user_id'         => 99999999,
                'verification_status' => ExternalIdentityMapping::STATUS_PENDING_VERIFICATION,
            ]);
            $this->fail('Expected QueryException for invalid ims_user_id foreign key');
        } catch (QueryException $e) {
            $this->assertTrue(true, 'Foreign key rejected invalid ims_user_id');
        }

        // 6B: Foreign key ims_registration_id non-existent
        $user = $this->createTestUser();
        $identity = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'ext_valid_user',
            'ims_user_id'         => $user->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        try {
            ExternalRegistrationMapping::create([
                'external_identity_mapping_id' => $identity->id,
                'source_system'                => 'absen_djuragan',
                'external_registration_id'     => 'ext_reg_non_existent_ims',
                'ims_registration_id'          => 88888888,
            ]);
            $this->fail('Expected QueryException for invalid ims_registration_id foreign key');
        } catch (QueryException $e) {
            $this->assertTrue(true, 'Foreign key rejected invalid ims_registration_id');
        }
    }

    /**
     * Kriteria 7: ON DELETE RESTRICT bekerja sesuai desain.
     */
    public function test_07_on_delete_restrict_enforced_by_database(): void
    {
        $user = $this->createTestUser();
        $reg = $this->createTestRegistration($user);

        $identity = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'ext_restrict_test',
            'ims_user_id'         => $user->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        $regMap = ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'absen_restrict_reg',
            'ims_registration_id'          => $reg->id,
        ]);

        // 7A: Menghapus User induk secara fisik harus ditolak oleh ON DELETE RESTRICT pada external_identity_mappings
        try {
            $user->forceDelete();
            $this->fail('Expected QueryException when force deleting user referenced by external identity');
        } catch (QueryException $e) {
            $this->assertTrue(true, 'RESTRICT prevented user physical deletion');
        }

        // 7B: Menghapus ExternalIdentityMapping induk harus ditolak oleh ON DELETE RESTRICT pada external_registration_mappings
        try {
            $identity->delete();
            $this->fail('Expected QueryException when deleting identity referenced by registration mapping');
        } catch (QueryException $e) {
            $this->assertTrue(true, 'RESTRICT prevented identity mapping deletion');
        }

        // 7C: Menghapus InternshipRegistration harus ditolak oleh ON DELETE RESTRICT pada external_registration_mappings
        try {
            $reg->forceDelete();
            $this->fail('Expected QueryException when deleting registration referenced by external mapping');
        } catch (QueryException $e) {
            $this->assertTrue(true, 'RESTRICT prevented registration deletion');
        }
    }

    /**
     * Kriteria 8: Relasi Eloquent mengembalikan data yang benar.
     */
    public function test_08_eloquent_relationships_return_correct_models(): void
    {
        $user = $this->createTestUser();
        $admin = $this->createTestUser('admin');
        $reg = $this->createTestRegistration($user);

        $identity = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'rel_test_ext_u',
            'ims_user_id'         => $user->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
            'verified_at'         => now(),
            'verified_by'         => $admin->id,
        ]);

        $regMap = ExternalRegistrationMapping::create([
            'external_identity_mapping_id' => $identity->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'rel_test_ext_r',
            'ims_registration_id'          => $reg->id,
        ]);

        // Verifikasi dari sisi ExternalIdentityMapping
        $this->assertEquals($user->id, $identity->user->id);
        $this->assertEquals($admin->id, $identity->verifiedBy->id);
        $this->assertCount(1, $identity->registrationMappings);
        $this->assertEquals($regMap->id, $identity->registrationMappings->first()->id);

        // Verifikasi dari sisi ExternalRegistrationMapping
        $this->assertEquals($identity->id, $regMap->identityMapping->id);
        $this->assertEquals($reg->id, $regMap->registration->id);

        // Verifikasi dari sisi User
        $user->refresh();
        $this->assertCount(1, $user->externalIdentityMappings);
        $this->assertEquals($identity->id, $user->externalIdentityMappings->first()->id);

        // Verifikasi dari sisi InternshipRegistration
        $reg->refresh();
        $this->assertCount(1, $reg->externalRegistrationMappings);
        $this->assertEquals($regMap->id, $reg->externalRegistrationMappings->first()->id);
    }

    /**
     * Kriteria 9: validateOwnership mengembalikan false ketika identitas pengguna berbeda.
     */
    public function test_09_validate_ownership_invariant(): void
    {
        $userAlice = $this->createTestUser();
        $userBob = $this->createTestUser();

        $regAlice = $this->createTestRegistration($userAlice);
        $regBob = $this->createTestRegistration($userBob);

        $identityAlice = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'ext_alice_owner',
            'ims_user_id'         => $userAlice->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        // Kasus 9A: Alice identity dipetakan ke registrasi Alice -> Valid (true)
        $validMap = new ExternalRegistrationMapping([
            'external_identity_mapping_id' => $identityAlice->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'ext_reg_alice',
            'ims_registration_id'          => $regAlice->id,
        ]);
        $validMap->setRelation('identityMapping', $identityAlice);
        $validMap->setRelation('registration', $regAlice);

        $this->assertTrue($validMap->validateOwnership(), 'validateOwnership must return true when user IDs match');

        // Kasus 9B: Alice identity dipetakan ke registrasi Bob (Cross-User Mismatch) -> Invalid (false)
        $invalidMap = new ExternalRegistrationMapping([
            'external_identity_mapping_id' => $identityAlice->id,
            'source_system'                => 'absen_djuragan',
            'external_registration_id'     => 'ext_reg_bob_hijack',
            'ims_registration_id'          => $regBob->id,
        ]);
        $invalidMap->setRelation('identityMapping', $identityAlice);
        $invalidMap->setRelation('registration', $regBob);

        $this->assertFalse($invalidMap->validateOwnership(), 'validateOwnership must return false when user IDs mismatch');
    }

    /**
     * Kriteria 10: Konsistensi source_system pada penyimpanan Eloquent.
     */
    public function test_10_source_system_consistency_on_saving(): void
    {
        $user = $this->createTestUser();
        $reg = $this->createTestRegistration($user);

        $identity = ExternalIdentityMapping::create([
            'source_system'       => 'absen_djuragan',
            'external_user_id'    => 'ext_sync_source_u',
            'ims_user_id'         => $user->id,
            'verification_status' => ExternalIdentityMapping::STATUS_VERIFIED,
        ]);

        // Buat mapping dengan source_system yang sengaja berbeda ('custom_source')
        $regMap = new ExternalRegistrationMapping([
            'external_identity_mapping_id' => $identity->id,
            'source_system'                => 'custom_source',
            'external_registration_id'     => 'ext_sync_source_r',
            'ims_registration_id'          => $reg->id,
        ]);
        $regMap->save();

        // Booted saving hook harus menyelaraskan source_system dengan identityMapping
        $regMap->refresh();
        $this->assertEquals('absen_djuragan', $regMap->source_system);
    }
}
