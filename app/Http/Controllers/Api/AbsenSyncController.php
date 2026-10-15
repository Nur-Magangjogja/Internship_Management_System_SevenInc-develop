<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AbsenSyncController extends Controller
{
    /**
     * Health check endpoint untuk koneksi integrasi dari Absen Djuragan ke Web Intern.
     * Endpoint register-from-absen ditangguhkan dan belum diaktifkan pada Fase A.
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
     * Pada Phase C.1A, endpoint bertindak sebagai fondasi keamanan (security foundation)
     * yang memvalidasi otentikasi, feature flag, dependensi skema, larangan kredensial,
     * serta perlindungan role tanpa melakukan mutasi database riil.
     */
    public function registerFromAbsen(\Illuminate\Http\Request $request): JsonResponse
    {
        // 1. Feature Flag: Default OFF (503 Service Unavailable jika belum diaktifkan)
        if (!config('services.absen_djuragan.inbound_enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Layanan inbound registration API saat ini dinonaktifkan.',
            ], 503);
        }

        // 2. Schema Dependency Gate: Tolak request jika skema target belum lengkap
        if (!\Illuminate\Support\Facades\Schema::hasColumns('internship_registrations', ['birth_place', 'religion'])) {
            return response()->json([
                'success' => false,
                'message' => 'Dependensi skema database target belum terpenuhi (kolom birth_place / religion tidak tersedia).',
            ], 503);
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('external_identity_mappings')
            || !\Illuminate\Support\Facades\Schema::hasTable('external_registration_mappings')
            || !\Illuminate\Support\Facades\Schema::hasTable('user_account_activations')) {
            return response()->json([
                'success' => false,
                'message' => 'Dependensi skema database target belum terpenuhi (tabel/kolom integrasi tidak lengkap).',
            ], 503);
        }

        // 3. Prohibited Credentials Check: Dilarang mengirim password / password_hash
        if ($request->has('password') || $request->has('password_hash')) {
            return response()->json([
                'success' => false,
                'message' => 'Field kredensial (password / password_hash) dilarang dikirim melalui API integrasi.',
            ], 422);
        }

        // 4. Strict Validation
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
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

        // 5. Anti-Hijacking Protection: Cegah collision dengan akun internal (Admin / HR Staff / Superadmin)
        $existingUser = \App\Models\User::where('email', $request->input('email'))->first();
        if ($existingUser && in_array($existingUser->role, [\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_HR_STAFF, 'superadmin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Email terdaftar sebagai staf internal. Registrasi pemagang via API ditolak.',
            ], 403);
        }

        // 6. Phase C.1A Foundational Gate: Data writing gated pending isolated Phase C.1C authorization
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

        // 7. Eksekusi registrasi inbound via InboundRegistrationService (Phase C.1C Authorized Test Gate)
        $service = app(\App\Services\AbsenIntegration\InboundRegistrationService::class);
        $result  = $service->handleInboundRegistration($validator->validated());

        return response()->json($result, $result['status_code'] ?? 200);
    }
}
