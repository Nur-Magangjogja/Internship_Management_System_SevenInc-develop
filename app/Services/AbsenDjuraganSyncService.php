<?php

namespace App\Services;

use App\Models\InternshipRegistration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AbsenDjuraganSyncService
{
    /**
     * Sinkronkan data pemagang yang disetujui/di-ACC oleh Admin ke Absen Djuragan (Cross-API V2).
     */
    public static function syncIntern(InternshipRegistration $intern, ?string $brandName = null, ?string $status = null): bool
    {
        try {
            $intern->loadMissing('user');
            $user = $intern->user;
            if (!$user) {
                Log::warning("Sinkronisasi ke Absen Djuragan dilewati: User terkait tidak ditemukan untuk Intern ID {$intern->id}.");
                return false;
            }

            $existingSyncUserId = null;
            if (\Illuminate\Support\Facades\Schema::hasTable('absen_sync_states')) {
                $existingSyncUserId = \App\Models\AbsenSyncState::where('registration_id', $intern->id)->value('absen_user_id');
            }

            // Resolusi nama divisi
            $divName = null;
            if (!empty($intern->division_id)) {
                $divName = \App\Models\Division::find($intern->division_id)?->name;
            } elseif (!empty($intern->internship_interest)) {
                $divName = $intern->internship_interest;
            }

            if ($divName) {
                $cleanDiv = strtolower(trim($divName));
                $stdMap = [
                    'programmer (front end / backend)'  => 'Programmer',
                    'programmer (frontend / backend)'   => 'Programmer',
                    'web programmer'                    => 'Programmer',
                    'administration'                    => 'Administrasi',
                    'human resources (hr)'              => 'Human Resource',
                    'human resources'                   => 'Human Resource',
                    'graphic designer (konten kreatif)' => 'Desain Grafis',
                    'graphic designer'                  => 'Desain Grafis',
                    'sales & marketing'                 => 'Marketing / Sales',
                    'sales and marketing'               => 'Marketing / Sales',
                    'public relations (marcomm)'        => 'Marcom/Public Relation',
                    'public relations'                  => 'Marcom/Public Relation',
                    'photographer'                      => 'Fotografer',
                    'videographer'                      => 'Videografer',
                    'pengelasan'                        => 'Las',
                    'content writter'                   => 'Content Writer',
                ];
                $divName = $stdMap[$cleanDiv] ?? $divName;
            }

            // Resolusi nama sekolah / institusi
            $instName = null;
            if (!empty($intern->institution_id)) {
                $instName = \App\Models\Institution::find($intern->institution_id)?->name;
            } elseif (!empty($intern->institution_name)) {
                $instName = $intern->institution_name;
            }

            // Resolusi brand yang ditentukan
            $effectiveBrand = $brandName;
            if (empty($effectiveBrand) && !empty($intern->brand_id)) {
                $effectiveBrand = \App\Models\Brand::find($intern->brand_id)?->name;
            }
            if (empty($effectiveBrand) && !empty($intern->brand)) {
                $effectiveBrand = $intern->brand;
            }

            $effectiveStatus = $status ?: ($intern->internship_status ?? 'accepted');

            $payload = [
                'absen_user_id'          => $existingSyncUserId ? (int) $existingSyncUserId : null,
                'email'                  => $user->email,
                'password_hash'          => $user->password, // Hash bcrypt dari IMS
                'full_name'              => $intern->fullname ?: $user->name,
                'phone'                  => $intern->phone_number,
                'parent_whatsapp_number' => $intern->supervisor_contact ?? null,
                'gender'                 => $intern->gender,
                'born_date'              => $intern->born_date,
                'birth_place'            => $intern->birth_place,
                'religion'               => $intern->religion,
                'school_name'            => $instName,
                'student_id'             => $intern->student_id,
                'division_name'          => $divName,
                'brand_name'             => $effectiveBrand,
                'brand_id'               => $intern->brand_id,
                'start_date'             => $intern->start_date,
                'end_date'               => $intern->end_date,
                'status'                 => $effectiveStatus,
            ];

            $primaryUrl = rtrim(config('services.absen_djuragan.url', 'http://absen-djuragan.test/api/v1/external'), '/');
            $apiKey = config('services.absen_djuragan.api_key', 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c');

            $candidateUrls = [];

            // Jika aplikasi berjalan di HTTPS, prioritaskan HTTPS untuk sinkronisasi
            if (request()->isSecure() || str_starts_with(config('app.url'), 'https://') || str_starts_with($primaryUrl, 'https://')) {
                $candidateUrls[] = preg_replace('/^http:\/\//i', 'https://', $primaryUrl) . '/register-sync';
            }
            $candidateUrls[] = $primaryUrl . '/register-sync';

            if (str_contains($primaryUrl, '.test')) {
                $candidateUrls[] = 'http://127.0.0.1:8000/api/v1/external/register-sync';
            } elseif (str_contains($primaryUrl, '127.0.0.1') || str_contains($primaryUrl, 'localhost')) {
                $candidateUrls[] = 'http://absen-djuragan.test/api/v1/external/register-sync';
            }

            foreach ($candidateUrls as $url) {
                try {
                    $response = Http::timeout(6)->withoutVerifying()->withHeaders([
                        'X-API-KEY' => $apiKey,
                        'Accept'    => 'application/json',
                    ])->post($url, $payload);

                    if ($response->successful()) {
                        $resData = $response->json('data') ?? [];
                        $absenUserId = $resData['absen_user_id'] ?? null;
                        $absenInternId = $resData['intern_id'] ?? null;

                        // Simpan status durable jika skema absen_sync_states tersedia
                        if (\Illuminate\Support\Facades\Schema::hasTable('absen_sync_states')) {
                            \App\Models\AbsenSyncState::updateOrCreate(
                                ['registration_id' => $intern->id],
                                [
                                    'sync_status'          => in_array($effectiveStatus, ['exited', 'completed', 'rejected'], true)
                                        ? \App\Models\AbsenSyncState::STATUS_REMOTE_ACTION_REQUIRED
                                        : \App\Models\AbsenSyncState::STATUS_SYNCED,
                                    'absen_user_id'        => $absenUserId,
                                    'intern_id'            => $absenInternId,
                                    'last_synced_at'       => now(),
                                    'last_response_status' => $response->status(),
                                    'last_error_message'   => null,
                                ]
                            );
                        }

                        Log::info("Sinkronisasi ACC/Update pemagang {$user->email} ({$effectiveStatus}) ke Absen Djuragan berhasil via {$url}. Ref Absen User ID: {$absenUserId}.");
                        return true;
                    } else {
                        Log::warning("Sinkronisasi ACC/Update pemagang {$user->email} ke {$url} gagal (HTTP {$response->status()}): " . $response->body());
                    }
                } catch (\Throwable $err) {
                    Log::warning("Koneksi sinkronisasi ke {$url} gagal: " . $err->getMessage());
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::error("Error sinkronisasi ACC ke Absen Djuragan: " . $e->getMessage());
            return false;
        }
    }
}
