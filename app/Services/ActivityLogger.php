<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    /**
     * Daftar field sensitif yang wajib disensor (redacted) dari pencatatan log.
     */
    protected static array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'admin_password',
        'token',
        'remember_token',
        'api_key',
        'secret',
        'secret_key',
        'credential',
        'authorization',
        'bearer',
        'private_key',
        'client_secret',
        'access_token',
        'refresh_token',
        'auth_token',
        'otp',
        'cookie',
        'cvv',
        'pin',
        'session_id',
    ];

    /**
     * Catat aktivitas umum ke tabel activity_logs.
     */
    public static function log(
        string $action,
        ?string $description = null,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?User $actor = null
    ): ActivityLog {
        // Ambil actor saat ini (fallback ke user yang sedang login, atau SYSTEM)
        $actorUser = $actor ?? Auth::user();

        $actorId    = $actorUser?->id;
        $actorName  = !empty($actorUser?->name) ? $actorUser->name : ($actorUser ? ($actorUser->email ?? 'Pengguna') : 'SYSTEM');
        $actorEmail = $actorUser?->email;
        $actorRole  = $actorUser?->role ?? 'system';

        // Subject extraction
        $subjectType = $subject ? get_class($subject) : null;
        $subjectId   = $subject?->getKey();
        $subjectRef  = null;

        if ($subject) {
            // Ambil public_uuid atau code atau reference jika ada
            $subjectRef = $subject->public_uuid ?? $subject->code ?? $subject->email ?? (string) $subject->getKey();
        }

        // Sensor seluruh payload before, after, metadata
        $safeBefore   = !empty($before) ? self::redact($before) : null;
        $safeAfter    = !empty($after) ? self::redact($after) : null;
        $safeMetadata = !empty($metadata) ? self::redact($metadata) : null;

        // Info request
        $ip = Request::ip();
        $ua = Request::userAgent();

        return ActivityLog::create([
            'actor_user_id'       => $actorId,
            'actor_name_snapshot' => $actorName,
            'actor_email_snapshot'=> $actorEmail,
            'actor_role_snapshot' => $actorRole,
            'action'              => $action,
            'subject_type'        => $subjectType,
            'subject_id'          => $subjectId,
            'subject_public_ref'  => $subjectRef,
            'description'         => $description,
            'before_data'         => $safeBefore,
            'after_data'          => $safeAfter,
            'metadata'            => $safeMetadata,
            'ip_address'          => $ip,
            'user_agent'          => $ua,
        ]);
    }

    /**
     * Rekursif menyaring data sensitif dari array.
     */
    public static function redact(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;

            foreach (self::$sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::redact($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
