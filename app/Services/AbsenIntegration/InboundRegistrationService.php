<?php

namespace App\Services\AbsenIntegration;

use App\Models\City;
use App\Models\Division;
use App\Models\ExternalIdentityMapping;
use App\Models\ExternalRegistrationMapping;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Models\UserAccountActivation;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class InboundRegistrationService
{
    /**
     * Memproses registrasi inbound dari Absen Djuragan ke IMS.
     *
     * @param array $payload Data registrasi tervalidasi
     * @return array Respon terstandarisasi dengan status_code
     */
    public function handleInboundRegistration(array $payload): array
    {
        $sourceSystem          = $payload['source_system'] ?? ExternalIdentityMapping::SOURCE_ABSEN_DJURAGAN;
        $externalUserId        = (string) ($payload['external_user_id'] ?? '');
        $externalRegistrationId = (string) ($payload['external_registration_id'] ?? '');
        $email                 = strtolower(trim($payload['email'] ?? ''));
        $name                  = trim($payload['name'] ?? '');
        $gender                = $this->normalizeGender($payload['gender'] ?? null);
        $birthPlace            = $payload['birth_place'] ?? null;
        $religion              = $payload['religion'] ?? null;
        $phone                 = $payload['phone'] ?? null;

        // 1. Anti-Account-Takeover: Lindungi akun staf internal (admin, hr_staff, superadmin)
        $existingUser = User::where('email', $email)->first();
        if ($existingUser && in_array($existingUser->role, [User::ROLE_ADMIN, User::ROLE_HR_STAFF, 'superadmin'], true)) {
            return [
                'success'     => false,
                'status_code' => 403,
                'message'     => 'Email terdaftar sebagai staf internal. Registrasi pemagang via API ditolak.',
            ];
        }

        // 2. Pemeriksaan Duplikasi Identitas Eksternal pada Akun Lain
        $existingIdentity = ExternalIdentityMapping::where('source_system', $sourceSystem)
            ->where('external_user_id', $externalUserId)
            ->first();

        if ($existingIdentity && $existingUser && (int) $existingIdentity->ims_user_id !== (int) $existingUser->id) {
            return [
                'success'     => false,
                'status_code' => 409,
                'message'     => 'Konflik identitas: external_user_id terhubung dengan akun pengguna IMS yang berbeda.',
            ];
        }

        if ($existingIdentity && !$existingUser) {
            return [
                'success'     => false,
                'status_code' => 409,
                'message'     => 'Konflik identitas: external_user_id telah terdaftar pada email akun yang berbeda.',
            ];
        }

        // 3. Penanganan Akun Existing IMS
        if ($existingUser) {
            // Jika akun existing belum memiliki identity mapping dari source_system ini,
            // tolak auto-linking untuk mencegah identity collision & account hijack via email saja
            if (!$existingIdentity) {
                return [
                    'success'     => false,
                    'status_code' => 409,
                    'message'     => 'Email sudah terdaftar pada sistem IMS namun belum memiliki pemetaan identitas eksternal terverifikasi.',
                ];
            }

            // External identity sudah terpetakan ke user existing ini. Periksa external_registration_id
            $existingRegMapping = ExternalRegistrationMapping::where('source_system', $sourceSystem)
                ->where('external_registration_id', $externalRegistrationId)
                ->first();

            if ($existingRegMapping) {
                // Jika sudah dipetakan ke registrasi milik user yang sama -> Idempotent request
                if ($existingRegMapping->identityMapping && (int) $existingRegMapping->identityMapping->ims_user_id === (int) $existingUser->id) {
                    return [
                        'success'     => true,
                        'status_code' => 200,
                        'message'     => 'Permintaan pendaftaran telah diproses sebelumnya (idempotent).',
                        'data'        => [
                            'user_id'                  => $existingUser->id,
                            'registration_id'          => $existingRegMapping->ims_registration_id,
                            'external_user_id'         => $externalUserId,
                            'external_registration_id' => $externalRegistrationId,
                            'is_idempotent'            => true,
                        ],
                    ];
                }

                // Terpetakan ke user lain -> Conflict
                return [
                    'success'     => false,
                    'status_code' => 409,
                    'message'     => 'Konflik pendaftaran: external_registration_id telah digunakan oleh registrasi lain.',
                ];
            }

            // Alur Multi-Periode: User existing yang sah menerima periode magang baru
            return $this->handleMultiPeriodRegistration(
                $existingUser,
                $existingIdentity,
                $sourceSystem,
                $externalRegistrationId,
                compact('name', 'email', 'phone', 'gender', 'birthPlace', 'religion')
            );
        }

        // 4. Pemeriksaan apakah external_registration_id sudah pernah dipakai
        $existingRegCheck = ExternalRegistrationMapping::where('source_system', $sourceSystem)
            ->where('external_registration_id', $externalRegistrationId)
            ->exists();

        if ($existingRegCheck) {
            return [
                'success'     => false,
                'status_code' => 409,
                'message'     => 'Konflik pendaftaran: external_registration_id telah digunakan.',
            ];
        }

        // 5. Alur Pembuatan Akun Baru & Pendaftaran Atomik
        return $this->handleNewUserRegistration(
            $sourceSystem,
            $externalUserId,
            $externalRegistrationId,
            compact('name', 'email', 'phone', 'gender', 'birthPlace', 'religion')
        );
    }

    /**
     * Membuat akun user baru, aktivasi, identitas eksternal, dan pendaftaran dalam transaksi atomik.
     */
    protected function handleNewUserRegistration(
        string $sourceSystem,
        string $externalUserId,
        string $externalRegistrationId,
        array $data
    ): array {
        $createdUser = null;
        $createdRegistration = null;
        $createdIdentity = null;
        $createdRegMapping = null;

        try {
            DB::transaction(function () use (
                $sourceSystem,
                $externalUserId,
                $externalRegistrationId,
                $data,
                &$createdUser,
                &$createdRegistration,
                &$createdIdentity,
                &$createdRegMapping
            ) {
                // A. Buat User baru (role pemagang, password acak kuat)
                $username = $this->generateUniqueUsername($data['name']);
                $createdUser = User::create([
                    'name'     => $username,
                    'email'    => $data['email'],
                    'password' => Hash::make(Str::random(32)),
                    'role'     => User::ROLE_PEMAGANG,
                ]);

                // B. Buat record Aktivasi Akun (status pending: activated_at = null)
                UserAccountActivation::create([
                    'user_id'                        => $createdUser->id,
                    'activated_at'                   => null,
                    'last_notification_requested_at' => now(),
                    'resend_count'                   => 1,
                ]);

                // C. Buat External Identity Mapping (pending verification)
                $createdIdentity = ExternalIdentityMapping::create([
                    'source_system'       => $sourceSystem,
                    'external_user_id'    => $externalUserId,
                    'ims_user_id'         => $createdUser->id,
                    'verification_status' => ExternalIdentityMapping::STATUS_PENDING_VERIFICATION,
                ]);

                // D. Master data setup untuk pendaftaran
                $masters = $this->resolveDefaultMasterData();

                // E. Buat Internship Registration
                $createdRegistration = InternshipRegistration::create([
                    'user_id'                 => $createdUser->id,
                    'fullname'                => $data['name'],
                    'student_id'              => '-',
                    'born_date'               => '2000-01-01',
                    'gender'                  => $data['gender'],
                    'phone_number'            => $data['phone'] ?? '-',
                    'institution_id'          => $masters['institution_id'],
                    'faculty_id'              => $masters['faculty_id'],
                    'study_program_id'        => $masters['study_program_id'],
                    'city_id'                 => $masters['city_id'],
                    'division_id'             => $masters['division_id'],
                    'internship_interest'     => $masters['division_name'],
                    'internship_type'         => 'Magang Mandiri',
                    'internship_arrangement'  => 'WFO',
                    'current_status'          => 'Mahasiswa Aktif',
                    'wfo_readiness'           => 'Siap',
                    'schedule_shift_readiness'=> 'Siap',
                    'has_laptop'              => true,
                    'planned_duration'        => '3 Bulan',
                    'internship_status'       => InternshipRegistration::STATUS_ACCEPTED,
                    'birth_place'             => $data['birthPlace'] ?? null,
                    'religion'                => $data['religion'] ?? null,
                ]);

                // F. Buat External Registration Mapping
                $createdRegMapping = new ExternalRegistrationMapping([
                    'external_identity_mapping_id' => $createdIdentity->id,
                    'source_system'                => $sourceSystem,
                    'external_registration_id'     => $externalRegistrationId,
                    'ims_registration_id'          => $createdRegistration->id,
                ]);

                // G. Validasi Kepemilikan (Registration Ownership Invariant) sebelum penyimpanan
                $createdRegMapping->setRelation('identityMapping', $createdIdentity);
                $createdRegMapping->setRelation('registration', $createdRegistration);

                if (!$createdRegMapping->validateOwnership()) {
                    throw new \RuntimeException('Pelanggaran integritas kepemilikan registrasi (Ownership validation failed).');
                }

                $createdRegMapping->save();

                // Auto-generate LOA untuk registrasi inbound
                try {
                    app(\App\Services\DocumentGeneratorService::class)->ensureLoaGenerated($createdRegistration);
                } catch (\Throwable $loaErr) {
                    Log::warning("Gagal auto-generate LOA inbound #{$createdRegistration->id}: " . $loaErr->getMessage());
                }

                // H. Dispatch notifikasi aktivasi akun setelah transaksi berhasil commit
                DB::afterCommit(function () use ($createdUser) {
                    try {
                        $token = Password::broker()->createToken($createdUser);
                        $createdUser->notify(new AccountActivationNotification($token));
                    } catch (\Throwable $e) {
                        Log::error('Gagal mengirimkan email notifikasi aktivasi: ' . $e->getMessage(), [
                            'user_id' => $createdUser->id,
                        ]);
                    }
                });
            });

            return [
                'success'     => true,
                'status_code' => 201,
                'message'     => 'Registrasi pemagang baru dari Absen Djuragan berhasil diproses. Email aktivasi telah dikirimkan.',
                'data'        => [
                    'user_id'                      => $createdUser->id,
                    'registration_id'              => $createdRegistration->id,
                    'external_user_id'             => $createdIdentity->external_user_id,
                    'external_registration_id'     => $createdRegMapping->external_registration_id,
                    'account_status'               => 'pending_activation',
                    'identity_verification_status' => $createdIdentity->verification_status,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Inbound registration failed with rollback: ' . $e->getMessage(), [
                'external_user_id' => $externalUserId,
                'email'            => $data['email'],
            ]);

            return [
                'success'     => false,
                'status_code' => 500,
                'message'     => 'Terjadi kesalahan sistem saat memproses registrasi: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Menangani pendaftaran periode baru untuk akun existing yang sudah memiliki pemetaan identitas sah.
     */
    protected function handleMultiPeriodRegistration(
        User $user,
        ExternalIdentityMapping $identity,
        string $sourceSystem,
        string $externalRegistrationId,
        array $data
    ): array {
        $newRegistration = null;
        $newRegMapping = null;

        try {
            DB::transaction(function () use (
                $user,
                $identity,
                $sourceSystem,
                $externalRegistrationId,
                $data,
                &$newRegistration,
                &$newRegMapping
            ) {
                $masters = $this->resolveDefaultMasterData();

                $newRegistration = InternshipRegistration::create([
                    'user_id'                 => $user->id,
                    'fullname'                => $data['name'] ?: $user->name,
                    'student_id'              => '-',
                    'born_date'               => '2000-01-01',
                    'gender'                  => $data['gender'],
                    'phone_number'            => $data['phone'] ?? '-',
                    'institution_id'          => $masters['institution_id'],
                    'faculty_id'              => $masters['faculty_id'],
                    'study_program_id'        => $masters['study_program_id'],
                    'city_id'                 => $masters['city_id'],
                    'division_id'             => $masters['division_id'],
                    'internship_interest'     => $masters['division_name'],
                    'internship_type'         => 'Magang Mandiri',
                    'internship_arrangement'  => 'WFO',
                    'current_status'          => 'Mahasiswa Aktif',
                    'wfo_readiness'           => 'Siap',
                    'schedule_shift_readiness'=> 'Siap',
                    'has_laptop'              => true,
                    'planned_duration'        => '3 Bulan',
                    'internship_status'       => InternshipRegistration::STATUS_ACCEPTED,
                    'birth_place'             => $data['birthPlace'] ?? null,
                    'religion'                => $data['religion'] ?? null,
                ]);

                $newRegMapping = new ExternalRegistrationMapping([
                    'external_identity_mapping_id' => $identity->id,
                    'source_system'                => $sourceSystem,
                    'external_registration_id'     => $externalRegistrationId,
                    'ims_registration_id'          => $newRegistration->id,
                ]);

                $newRegMapping->setRelation('identityMapping', $identity);
                $newRegMapping->setRelation('registration', $newRegistration);

                if (!$newRegMapping->validateOwnership()) {
                    throw new \RuntimeException('Ownership validation failed during multi-period registration.');
                }

                $newRegMapping->save();
            });

            return [
                'success'     => true,
                'status_code' => 201,
                'message'     => 'Pendaftaran periode baru berhasil ditambahkan pada akun existing.',
                'data'        => [
                    'user_id'                  => $user->id,
                    'registration_id'          => $newRegistration->id,
                    'external_user_id'         => $identity->external_user_id,
                    'external_registration_id' => $newRegMapping->external_registration_id,
                    'is_multi_period'          => true,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success'     => false,
                'status_code' => 500,
                'message'     => 'Gagal menambahkan pendaftaran periode baru: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Memastikan username acak dan unik dari nama lengkap.
     */
    protected function generateUniqueUsername(string $name): string
    {
        $base = Str::slug($name, '');
        if (empty($base)) {
            $base = 'pemagang';
        }
        $base = substr($base, 0, 100);

        $username = $base;
        $counter = 1;
        while (User::where('name', $username)->exists()) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Menyediakan relasi master data default untuk pendaftaran baru.
     */
    protected function resolveDefaultMasterData(): array
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

        return [
            'city_id'          => $city->id,
            'institution_id'   => $inst->id,
            'faculty_id'       => $fac->id,
            'study_program_id' => $prog->id,
            'division_id'      => $div->id,
            'division_name'    => $div->name,
        ];
    }

    /**
     * Normalisasi format gender.
     */
    protected function normalizeGender(?string $gender): string
    {
        if (empty($gender)) {
            return 'Laki-laki';
        }

        $g = strtolower(trim($gender));
        if (in_array($g, ['l', 'laki-laki', 'male'], true)) {
            return 'Laki-laki';
        }
        if (in_array($g, ['p', 'perempuan', 'female'], true)) {
            return 'Perempuan';
        }

        return 'Laki-laki';
    }
}
