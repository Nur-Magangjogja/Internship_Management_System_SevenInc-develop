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

class AbsenSyncController extends Controller
{
    /**
     * Health check endpoint untuk koneksi dari Absen Djuragan ke Web Intern.
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
     * Endpoint untuk menerima data pendaftaran pemagang dari Absen Djuragan.
     * Membuat akun User dan membuat/memperbarui data di daftar magang (internship_registrations).
     */
    public function registerFromAbsen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'            => 'required|email|max:150',
            'username'         => 'nullable|string|max:150',
            'password'         => 'nullable|string|min:6',
            'password_hash'    => 'nullable|string',
            'full_name'        => 'required|string|max:150',
            'phone'            => 'nullable|string|max:30',
            'gender'           => 'nullable|string|max:20',
            'date_of_birth'    => 'nullable|date',
            'birth_place'      => 'nullable|string|max:100',
            'religion'         => 'nullable|string|max:50',
            'address'          => 'nullable|string|max:500',
            'school_name'      => 'nullable|string|max:255',
            'school_id'        => 'nullable|integer',
            'nim'              => 'nullable|string|max:50',
            'is_gps_available' => 'nullable',
            'division_name'    => 'nullable|string|max:100',
            'division_id'      => 'nullable|integer',
            'brand_name'       => 'nullable|string|max:100',
            'brand_id'         => 'nullable|integer',
        ]);

        DB::beginTransaction();
        try {
            // 1. Identifikasi / Buat Akun User di Intern
            $user = User::where('email', $validated['email'])->first();
            $isNewUser = false;

            $isGps = isset($validated['is_gps_available']) ? (bool) $validated['is_gps_available'] : true;

            if (!$user) {
                $isNewUser = true;

                $user = new User([
                    'name'            => $validated['full_name'],
                    'email'           => $validated['email'],
                    'role'            => 'user',
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
                // Perbarui password jika dikirim dan user bukan admin
                if ($user->role !== 'admin') {
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

            // 2. Resolusi Sekolah / Institusi
            $institutionId = null;
            if (!empty($validated['school_name'])) {
                $cleanSchool = trim($validated['school_name']);
                $inst = Institution::where('name', 'like', '%' . $cleanSchool . '%')->first();
                if (!$inst) {
                    $inst = Institution::create(['name' => $cleanSchool]);
                }
                $institutionId = $inst->id;
            }
            if (!$institutionId) {
                $institutionId = Institution::first()?->id ?? 1;
            }

            // 3. Resolusi Fakultas
            $faculty = Faculty::where('institution_id', $institutionId)->first();
            if (!$faculty) {
                $faculty = Faculty::create([
                    'institution_id' => $institutionId,
                    'name'           => 'Umum',
                ]);
            }
            $facultyId = $faculty->id;

            // 4. Resolusi Program Studi
            $studyProgram = StudyProgram::where('faculty_id', $facultyId)->first();
            if (!$studyProgram) {
                $studyProgram = StudyProgram::create([
                    'faculty_id' => $facultyId,
                    'name'       => 'Umum',
                ]);
            }
            $studyProgramId = $studyProgram->id;

            // 5. Resolusi Kota
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

            // 6. Resolusi Divisi
            $divisionId = null;
            if (!empty($validated['division_id']) && Division::where('id', $validated['division_id'])->exists()) {
                $divisionId = (int) $validated['division_id'];
            } elseif (!empty($validated['division_name'])) {
                $divisionId = RegistrationController::resolveDivisionId($validated['division_name']);
            }
            if (!$divisionId) {
                $divisionId = Division::first()?->id ?? 1;
            }

            // 7. Resolusi Brand (Opsional)
            $brandId = null;
            if (!empty($validated['brand_id']) && \App\Models\Brand::where('id', $validated['brand_id'])->exists()) {
                $brandId = (int) $validated['brand_id'];
            } elseif (!empty($validated['brand_name'])) {
                $brand = \App\Models\Brand::where('name', 'like', '%' . trim($validated['brand_name']) . '%')->first();
                $brandId = $brand?->id;
            }

            // 8. Normalisasi Gender & Tanggal Lahir
            $gender = 'Laki-laki';
            if (!empty($validated['gender'])) {
                $g = strtolower($validated['gender']);
                if (str_contains($g, 'perempuan') || $g === 'p' || $g === 'female') {
                    $gender = 'Perempuan';
                }
            }

            $bornDate = !empty($validated['date_of_birth']) ? $validated['date_of_birth'] : now()->subYears(18)->format('Y-m-d');
            try {
                $bornDate = Carbon::parse($bornDate)->format('Y-m-d');
            } catch (\Throwable $e) {
                $bornDate = now()->subYears(18)->format('Y-m-d');
            }

            // 9. Simpan / Perbarui Formulir Daftar Magang (internship_registrations)
            $registration = IR::where('user_id', $user->id)->first();
            if (!$registration) {
                $registration = IR::create([
                    'user_id'                => $user->id,
                    'fullname'               => $validated['full_name'],
                    'born_date'              => $bornDate,
                    'birth_place'            => $validated['birth_place'] ?? null,
                    'gender'                 => $gender,
                    'religion'               => $validated['religion'] ?? null,
                    'phone_number'           => !empty($validated['phone']) ? $validated['phone'] : '-',
                    'city_id'                => $cityId,
                    'student_id'             => !empty($validated['nim']) ? $validated['nim'] : '-',
                    'institution_id'         => $institutionId,
                    'faculty_id'             => $facultyId,
                    'study_program_id'       => $studyProgramId,
                    'internship_reason'      => 'Pendaftaran via Absen Djuragan',
                    'internship_type'        => 'PKL',
                    'internship_arrangement' => 'Onsite',
                    'current_status'         => 'Mahasiswa/Pelajar',
                    'english_book_ability'   => 'Saya bisa',
                    'division_id'            => $divisionId,
                    'brand_id'               => $brandId,
                    'internship_status'      => IR::STATUS_WAITING, // 'waiting'
                    'current_activities'     => $validated['address'] ?? null,
                ]);
            } else {
                $updateData = [
                    'fullname'     => $validated['full_name'],
                    'born_date'    => $bornDate,
                    'gender'       => $gender,
                    'phone_number' => !empty($validated['phone']) ? $validated['phone'] : $registration->phone_number,
                ];
                if (!empty($validated['birth_place'])) $updateData['birth_place'] = $validated['birth_place'];
                if (!empty($validated['religion'])) $updateData['religion'] = $validated['religion'];
                if (!empty($validated['nim'])) $updateData['student_id'] = $validated['nim'];
                if (!empty($validated['address'])) $updateData['current_activities'] = $validated['address'];

                if ($institutionId && $registration->institution_id == 1 && $institutionId != 1) {
                    $updateData['institution_id']   = $institutionId;
                    $updateData['faculty_id']       = $facultyId;
                    $updateData['study_program_id'] = $studyProgramId;
                }
                if ($divisionId && $registration->division_id == 1 && $divisionId != 1) {
                    $updateData['division_id'] = $divisionId;
                }
                if ($brandId && empty($registration->brand_id)) {
                    $updateData['brand_id'] = $brandId;
                }

                $registration->update($updateData);
            }

            DB::commit();

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
            ], $isNewUser ? 201 : 200);

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
