<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pemagang\RegistrationController;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\StudyProgram;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AbsenSyncController extends Controller
{
    /**
     * Health check endpoint untuk koneksi integrasi dari Absen Djuragan ke Web Intern.
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'success'     => true,
            'message'     => 'Internship Management System External API is healthy and connected.',
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Endpoint registrasi inbound dari Absen Djuragan ke IMS.
     * Mendukung format kontrak Phase C (Enterprise Mapping via InboundRegistrationService)
     * dan format kontrak langsung dari Web Absen.
     */
    public function registerFromAbsen(Request $request): JsonResponse
    {
        // 1. Feature Flag: Default OFF jika dinonaktifkan secara eksplisit (503 Service Unavailable)
        if (!config('services.absen_djuragan.inbound_enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Layanan inbound registration API saat ini dinonaktifkan.',
            ], 503);
        }

        // 2. Jika request merupakan format Phase C (memiliki source_system, kredensial terlarang pada Phase C, atau payload kosong untuk validasi ketat)
        if ($request->has('source_system') || empty($request->all()) || ($request->has('external_user_id') && ($request->has('password') || $request->has('password_hash')))) {
            // Dependensi skema
            if (!Schema::hasColumns('internship_registrations', ['birth_place', 'religion'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dependensi skema database target belum terpenuhi (kolom birth_place / religion tidak tersedia).',
                ], 503);
            }

            if (!Schema::hasTable('external_identity_mappings')
                || !Schema::hasTable('external_registration_mappings')
                || !Schema::hasTable('user_account_activations')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dependensi skema database target belum terpenuhi (tabel/kolom integrasi tidak lengkap).',
                ], 503);
            }

            // Larangan pengiriman password pada kontrak Phase C
            if ($request->has('password') || $request->has('password_hash')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Field kredensial (password / password_hash) dilarang dikirim melalui API integrasi.',
                ], 422);
            }

            // Strict Validation Phase C
            $validator = Validator::make($request->all(), [
                'source_system'            => ['required', 'string', 'in:absen_djuragan'],
                'external_user_id'         => ['required', 'string', 'max:191'],
                'external_registration_id' => ['required', 'string', 'max:191'],
                'email'                    => ['required', 'email', 'max:150'],
                'name'                     => ['required', 'string', 'max:150'],
                'gender'                   => ['nullable', 'string', 'in:L,P,Laki-laki,Perempuan,male,female'],
                'birth_place'              => ['nullable', 'string', 'max:100'],
                'religion'                 => ['nullable', 'string', 'max:50'],
                'phone'                    => ['nullable', 'string', 'max:30'],
            ], [
                'source_system.required'            => 'Field source_system wajib disertakan.',
                'source_system.in'                  => 'Field source_system harus berupa absen_djuragan.',
                'external_user_id.required'         => 'Field external_user_id wajib disertakan.',
                'external_registration_id.required' => 'Field external_registration_id wajib disertakan.',
                'email.required'                    => 'Field email wajib disertakan.',
                'email.email'                       => 'Format email tidak valid.',
                'name.required'                     => 'Field name wajib disertakan.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi data registrasi inbound gagal.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // Anti-Hijacking Protection: Cegah collision dengan akun staf internal
            $existingUser = User::where('email', $request->input('email'))->first();
            if ($existingUser && in_array($existingUser->role, [User::ROLE_ADMIN, User::ROLE_HR_STAFF, 'superadmin'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email terdaftar sebagai staf internal. Registrasi pemagang via API ditolak.',
                ], 403);
            }

            // Phase C.1A Foundational Gate: Data writing gated pending isolated Phase C.1C authorization
            if (!config('services.absen_djuragan.inbound_write_enabled', false)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Fondasi keamanan inbound API tervalidasi. Penulisan data ditangguhkan menunggu otorisasi Phase C.1B.',
                    'data'    => [
                        'source_system'            => $request->input('source_system'),
                        'external_user_id'         => $request->input('external_user_id'),
                        'external_registration_id' => $request->input('external_registration_id'),
                        'email'                    => $request->input('email'),
                        'user_status'              => $existingUser ? 'existing_pemagang_found' : 'new_pemagang_candidate',
                        'schema_ready'             => true,
                        'mutations_performed'      => false,
                    ],
                ], 200);
            }

            // Phase C.1C Eksekusi registrasi inbound via InboundRegistrationService
            $service = app(\App\Services\AbsenIntegration\InboundRegistrationService::class);
            $result  = $service->handleInboundRegistration($validator->validated());

            return response()->json($result, $result['status_code'] ?? 200);
        }

        // 3. Format Registrasi Langsung dari Web Absen (feat--API-to-web-absen)
        $validated = $request->validate([
            'email'            => 'required|email|max:150',
            'username'         => 'nullable|string|max:150',
            'password'         => 'nullable|string|min:6',
            'password_hash'    => 'nullable|string',
            'full_name'        => 'required|string|max:150',
            'phone'            => 'nullable|string|max:30',
            'gender'           => 'nullable|string|max:20',
            'date_of_birth'    => 'nullable',
            'birth_place'      => 'nullable|string|max:100',
            'religion'         => 'nullable|string|max:50',
            'address'          => 'nullable|string|max:500',
            'school_name'      => 'nullable|string|max:255',
            'school_id'        => 'nullable|integer',
            'nim'              => 'nullable|string|max:50',
            'is_gps_available' => 'nullable',
            'division_name'          => 'nullable|string|max:100',
            'division_id'            => 'nullable|integer',
            'brand_name'             => 'nullable|string|max:100',
            'brand_id'               => 'nullable|integer',
            'parent_whatsapp_number' => 'nullable|string|max:50',
            'start_date'             => 'nullable',
            'end_date'               => 'nullable',
            'is_active'              => 'nullable',
            'status'                 => 'nullable|string|max:50',
        ]);

        DB::beginTransaction();
        try {
            $user = User::where('email', $validated['email'])->first();
            $isNewUser = false;
            $isGps = isset($validated['is_gps_available']) ? (bool) $validated['is_gps_available'] : true;

            $targetRole = 'pemagang';

            if (!$user) {
                $isNewUser = true;
                $user = new User([
                    'name'            => $validated['full_name'],
                    'email'           => $validated['email'],
                    'role'            => $targetRole,
                    'is_gps_support'  => $isGps,
                    'is_gps_active'   => $isGps,
                    'is_gps_activate' => $isGps,
                ]);

                if (!empty($validated['password_hash'])) {
                    $user->setRawAttributes(array_merge($user->getAttributes(), ['password' => $validated['password_hash']]));
                } elseif (!empty($validated['password'])) {
                    $user->password = Hash::make($validated['password']);
                } else {
                    $user->password = Hash::make('password123');
                }

                $user->save();
            } else {
                if (!in_array($user->role, ['admin', 'superadmin', 'hr_staff'], true)) {
                    $user->role = $targetRole;
                    if (!empty($validated['password_hash'])) {
                        $user->setRawAttributes(array_merge($user->getAttributes(), ['password' => $validated['password_hash']]));
                    } elseif (!empty($validated['password'])) {
                        $user->password = Hash::make($validated['password']);
                    }
                }

                if (empty($user->name) || $user->name === '-') {
                    $user->name = $validated['full_name'];
                }

                if (isset($validated['is_gps_available'])) {
                    $user->is_gps_support  = $isGps;
                    $user->is_gps_active   = $isGps;
                    $user->is_gps_activate = $isGps;
                }

                $user->save();
            }

            // Resolusi Sekolah / Institusi
            $institutionId = null;
            if (!empty($validated['school_name'])) {
                $cleanSchool = trim($validated['school_name']);
                $normName = Institution::normalizeName($cleanSchool);
                $inst = Institution::whereRaw('LOWER(name) = ?', [strtolower($cleanSchool)])
                    ->orWhere('normalized_name', $normName)
                    ->orWhere('name', 'like', '%' . $cleanSchool . '%')
                    ->first();
                if (!$inst) {
                    $inst = Institution::create([
                        'name'            => ucwords(strtolower($cleanSchool)),
                        'normalized_name' => $normName,
                        'is_active'       => true,
                    ]);
                }
                $institutionId = $inst->id;
            }
            if (!$institutionId) {
                $institutionId = Institution::first()?->id ?? 1;
            }

            // Resolusi Fakultas
            $faculty = Faculty::where('institution_id', $institutionId)->first();
            if (!$faculty) {
                $faculty = Faculty::create([
                    'institution_id' => $institutionId,
                    'name'           => 'Umum',
                ]);
            }
            $facultyId = $faculty->id;

            // Resolusi Program Studi
            $studyProgram = StudyProgram::where('faculty_id', $facultyId)->first();
            if (!$studyProgram) {
                $studyProgram = StudyProgram::create([
                    'faculty_id' => $facultyId,
                    'name'       => 'Umum',
                ]);
            }
            $studyProgramId = $studyProgram->id;

            // Resolusi Kota
            $cityId = null;
            if (!empty($validated['address'])) {
                $addr = strtolower($validated['address']);
                $matchedCity = City::all()->first(function ($c) use ($addr) {
                    return str_contains($addr, strtolower($c->name));
                });
                if ($matchedCity) {
                    $cityId = $matchedCity->id;
                }
            }
            if (!$cityId) {
                $cityId = City::first()?->id ?? 1;
            }

            // Resolusi Divisi (Prioritaskan nama divisi via kamus sinonim)
            $divisionId = null;
            if (!empty($validated['division_name'])) {
                $divisionId = RegistrationController::resolveDivisionId($validated['division_name']);
            } elseif (!empty($validated['division_id']) && Division::where('id', $validated['division_id'])->exists()) {
                $divisionId = (int) $validated['division_id'];
            }
            if (!$divisionId) {
                $divisionId = Division::first()?->id ?? 1;
            }

            // Resolusi Brand (Prioritaskan nama brand lintas sistem)
            $brandId = null;
            if (!empty($validated['brand_name'])) {
                $cleanBrand = strtolower(str_replace([' ', '-', '_'], '', trim($validated['brand_name'])));
                $brand = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim($validated['brand_name']))])
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(name, ' ', ''), '-', '')) = ?", [$cleanBrand])
                    ->first();
                if (!$brand) {
                    $brand = \App\Models\Brand::create([
                        'code' => \Illuminate\Support\Str::slug($validated['brand_name']),
                        'name' => trim($validated['brand_name']),
                    ]);
                }
                $brandId = $brand?->id;
            } elseif (!empty($validated['brand_id']) && \App\Models\Brand::where('id', $validated['brand_id'])->exists()) {
                $brandId = (int) $validated['brand_id'];
            }

            // Normalisasi Gender & Tanggal Lahir
            $gender = null;
            if (!empty($validated['gender'])) {
                $g = strtolower(trim((string) $validated['gender']));
                if (in_array($g, ['perempuan', 'p', 'female', 'wanita'], true) || str_contains($g, 'perempuan')) {
                    $gender = 'Perempuan';
                } elseif (in_array($g, ['laki-laki', 'l', 'male', 'pria'], true) || str_contains($g, 'laki')) {
                    $gender = 'Laki-laki';
                } else {
                    $gender = $validated['gender'];
                }
            }

            $bornDate = !empty($validated['date_of_birth']) ? $validated['date_of_birth'] : now()->subYears(18)->format('Y-m-d');
            try {
                $bornDate = Carbon::parse($bornDate)->format('Y-m-d');
            } catch (\Throwable $e) {
                $bornDate = now()->subYears(18)->format('Y-m-d');
            }

            // Tentukan status pendaftaran: pemagang dari Absen terdaftar sebagai aktif (atau sesuai status eksplisit)
            $targetStatus = !empty($validated['status']) ? $validated['status'] : IR::STATUS_ACTIVE;
            if (isset($validated['is_active']) && !$validated['is_active']) {
                $targetStatus = IR::STATUS_WAITING;
            }

            // Simpan / Perbarui Formulir Daftar Magang (internship_registrations)
            $registration = IR::where('user_id', $user->id)->first();
            if (!$registration) {
                $registration = IR::whereHas('user', function ($q) use ($validated) {
                    $q->where('email', $validated['email']);
                })->first();
            }

            if (!$registration) {
                $registration = IR::create([
                    'user_id'                => $user->id,
                    'fullname'               => $validated['full_name'],
                    'born_date'              => $bornDate,
                    'birth_place'            => $validated['birth_place'] ?? null,
                    'gender'                 => $gender ?? 'Laki-laki',
                    'religion'               => $validated['religion'] ?? null,
                    'phone_number'           => !empty($validated['phone']) ? $validated['phone'] : '-',
                    'supervisor_contact'     => !empty($validated['parent_whatsapp_number']) ? $validated['parent_whatsapp_number'] : null,
                    'city_id'                => $cityId,
                    'student_id'             => !empty($validated['nim']) ? $validated['nim'] : '-',
                    'institution_id'         => $institutionId,
                    'faculty_id'             => $facultyId,
                    'study_program_id'       => $studyProgramId,
                    'start_date'             => !empty($validated['start_date']) ? $validated['start_date'] : null,
                    'end_date'               => !empty($validated['end_date']) ? $validated['end_date'] : null,
                    'internship_reason'      => 'Pendaftaran via Absen Djuragan',
                    'internship_type'        => 'PKL',
                    'internship_arrangement' => 'Onsite',
                    'current_status'         => 'Mahasiswa/Pelajar',
                    'english_book_ability'   => 'Saya bisa',
                    'division_id'            => $divisionId,
                    'brand_id'               => $brandId,
                    'internship_status'      => $targetStatus,
                    'followup_status'        => ($targetStatus === IR::STATUS_ACTIVE) ? IR::FOLLOWUP_INTERNSHIP_STARTED : null,
                    'current_activities'     => $validated['address'] ?? null,
                ]);
            } else {
                $updateData = [
                    'fullname'     => $validated['full_name'],
                    'born_date'    => $bornDate,
                    'phone_number' => !empty($validated['phone']) ? $validated['phone'] : $registration->phone_number,
                    'user_id'      => $user->id,
                ];

                if (empty($registration->internship_reason)) {
                    $updateData['internship_reason'] = 'Pendaftaran via Absen Djuragan';
                }
                if ($gender !== null) {
                    $updateData['gender'] = $gender;
                }
                if (!empty($validated['parent_whatsapp_number'])) $updateData['supervisor_contact'] = $validated['parent_whatsapp_number'];
                if (!empty($validated['birth_place'])) $updateData['birth_place'] = $validated['birth_place'];
                if (!empty($validated['religion'])) $updateData['religion'] = $validated['religion'];
                if (!empty($validated['nim'])) $updateData['student_id'] = $validated['nim'];
                if (!empty($validated['address'])) $updateData['current_activities'] = $validated['address'];
                if (!empty($validated['start_date'])) $updateData['start_date'] = $validated['start_date'];
                if (!empty($validated['end_date'])) $updateData['end_date'] = $validated['end_date'];

                if ($institutionId) {
                    $updateData['institution_id']   = $institutionId;
                    $updateData['faculty_id']       = $facultyId;
                    $updateData['study_program_id'] = $studyProgramId;
                }
                if ($divisionId) {
                    $updateData['division_id'] = $divisionId;
                }
                if ($brandId) {
                    $updateData['brand_id'] = $brandId;
                }

                // Update status aktif jika dikirim atau sebelumnya berstatus belum diputuskan
                if ($targetStatus === IR::STATUS_ACTIVE || in_array($registration->internship_status, [IR::STATUS_WAITING, IR::STATUS_NEW, IR::STATUS_PENDING, null], true)) {
                    $updateData['internship_status'] = $targetStatus;
                    if ($targetStatus === IR::STATUS_ACTIVE && empty($registration->followup_status)) {
                        $updateData['followup_status'] = IR::FOLLOWUP_INTERNSHIP_STARTED;
                    }
                }

                $registration->update($updateData);
            }

            // Catat verifikasi penerimaan ke ActivityLog untuk jaminan kelayakan sinkronisasi spreadsheet (S17.17)
            if (in_array($targetStatus, [IR::STATUS_ACTIVE, IR::STATUS_ACCEPTED], true)) {
                \App\Services\ActivityLogger::log(
                    action: 'intern_registration_status_updated',
                    description: "Pendaftaran pemagang dari Absen Djuragan terverifikasi aktif.",
                    subject: $registration,
                    before: ['internship_status' => $registration->internship_status],
                    after: ['internship_status' => $targetStatus],
                    metadata: [
                        'registration_id' => $registration->id,
                        'user_id'         => $registration->user_id,
                        'new_status'      => IR::STATUS_ACCEPTED,
                    ]
                );
            }

            DB::commit();

            // Antrekan sinkronisasi otomatis ke Google Spreadsheet
            try {
                app(\App\Services\SpreadsheetSyncEngineService::class)->queueSyncForRegistration($registration);
            } catch (\Throwable $sheetErr) {
                Log::warning("Spreadsheet queue sync error for Absen registration #{$registration->id}: " . $sheetErr->getMessage());
            }

            Log::info("Pendaftaran dari Absen Djuragan berhasil disinkronkan ke Intern: {$validated['email']}");

            return response()->json([
                'success' => true,
                'message' => $isNewUser
                    ? 'Akun dan formulir pendaftaran magang berhasil dibuat di Internship Management System.'
                    : 'Akun dan formulir pendaftaran magang berhasil diperbarui di Internship Management System.',
                'data'    => [
                    'user_id'           => $user->id,
                    'name'              => $user->name,
                    'email'             => $user->email,
                    'role'              => $user->role,
                    'registration_id'   => $registration->id,
                    'internship_status' => $registration->internship_status,
                    'division_id'       => $registration->division_id,
                    'institution_id'    => $registration->institution_id,
                ],
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Register from Absen Error in Intern System: ' . $e->getMessage(), [
                'trace'   => $e->getTraceAsString(),
                'payload' => $request->except(['password']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pendaftaran dari Absen Djuragan: ' . $e->getMessage(),
            ], 500);
        }
    }
}
