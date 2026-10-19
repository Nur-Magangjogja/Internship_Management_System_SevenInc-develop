<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Division;
use App\Models\InternLoa;
use App\Models\InternshipRegistration;
use App\Models\SklDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentGeneratorService
{
    /**
     * Daftar role internal staf yang berhak membuat atau mengunduh dokumen atas nama pengguna lain.
     */
    public const PRIVILEGED_ROLES = ['admin', 'superadmin', 'super_admin', 'hr_staff', 'staff', 'hrd'];

    /**
     * Periksa apakah pemagang memenuhi syarat untuk dokumen tertentu.
     */
    public function canGenerate(InternshipRegistration $intern, string $documentType): bool
    {
        $status = strtolower((string) ($intern->internship_status ?? ''));

        return match (strtolower($documentType)) {
            'loa'                   => in_array($status, [
                InternshipRegistration::STATUS_ACCEPTED,
                InternshipRegistration::STATUS_ACTIVE,
                InternshipRegistration::STATUS_COMPLETED
            ], true),
            'skl',
            'sertifikat',
            'certificate',
            'penilaian',
            'assessment',
            'rekomendasi',
            'recommendation',
            'membercard'            => in_array($status, [InternshipRegistration::STATUS_COMPLETED], true),
            default                 => false,
        };
    }

    /**
     * Resolusi pengguna target dokumen secara aman tanpa kebocoran konteks otentikasi.
     */
    public function resolveTargetUser(User $actor, ?int $targetUserId = null): User
    {
        if (empty($targetUserId) || $targetUserId === $actor->id) {
            return $actor;
        }

        if (!in_array($actor->role, self::PRIVILEGED_ROLES, true)) {
            throw new HttpException(403, 'Anda tidak memiliki wewenang untuk mengunduh dokumen atas nama pengguna lain.');
        }

        return User::findOrFail($targetUserId);
    }

    /**
     * Catat rekam jejak audit saat dokumen digenerate atau diunduh atas nama pengguna lain.
     */
    public function logDocumentAccess(
        string $documentType,
        Model $subject,
        User $actor,
        string $actionType = 'generated'
    ): ActivityLog {
        $actionName = $actionType === 'downloaded' ? 'document_downloaded_for_user' : 'document_generated_for_user';
        $subjectName = $subject instanceof InternshipRegistration
            ? ($subject->fullname ?? 'Pemagang #' . $subject->id)
            : ($subject->name ?? 'User #' . $subject->id);

        return ActivityLogger::log(
            action: $actionName,
            description: strtoupper($documentType) . " milik {$subjectName} telah di-{$actionType} oleh {$actor->name}",
            subject: $subject,
            actor: $actor,
            metadata: [
                'document_type' => $documentType,
                'actor_role'    => $actor->role,
                'target_id'     => $subject->getKey(),
            ]
        );
    }

    /**
     * Hasilkan nomor surat LOA yang unik dan aman dari race condition menggunakan DB transaction & row lock.
     */
    public function generateUniqueLoaNumber(InternshipRegistration $intern, ?Brand $brand = null): string
    {
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        $brandModel = $brand ?? $intern->brandRel;
        $brandStr = strtoupper($brandModel?->code ?? 'SI');

        $divisionCode = 'UMUM';
        if ($intern->division) {
            $divisionCode = $intern->division->code ?? 'UMUM';
        } elseif ($intern->internship_interest) {
            $interest = $intern->internship_interest;
            $dbDivision = Division::where('name', $interest)->orWhere('slug', Str::slug($interest, '-'))->first();
            $divisionCode = $dbDivision?->code ?? 'UMUM';
        }

        return DB::transaction(function () use ($intern, $divisionCode, $brandStr, $romanMonth, $year) {
            // Kunci baris terakhir untuk bulan/tahun ini
            $last = InternLoa::where('loa_number', 'LIKE', "%/LOA/%/{$romanMonth}/{$year}")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $seq = 1;
            if ($last && preg_match('/^(\d{3})\/LOA\//', $last->loa_number, $m)) {
                $seq = (int)$m[1] + 1;
            }

            // Loop verifikasi keunikan nomor jika sudah ada di DB
            do {
                $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                $candidateNumber = "{$seqStr}/LOA/{$divisionCode}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
                $exists = InternLoa::where('loa_number', $candidateNumber)->exists();
                if ($exists) {
                    $seq++;
                }
            } while ($exists);

            return $candidateNumber;
        });
    }

    /**
     * Hasilkan nomor surat SKL yang unik dan aman dari race condition menggunakan DB transaction & row lock.
     */
    public function generateUniqueSklNumber(InternshipRegistration $intern, ?Brand $brand = null): string
    {
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        $brandModel = $brand ?? $intern->brandRel;
        $brandStr = strtoupper($brandModel?->code ?? 'SI');

        $divisionCode = 'UMUM';
        if ($intern->division) {
            $divisionCode = $intern->division->code ?? 'UMUM';
        } elseif ($intern->internship_interest) {
            $interest = $intern->internship_interest;
            $dbDivision = Division::where('name', $interest)->orWhere('slug', Str::slug($interest, '-'))->first();
            $divisionCode = $dbDivision?->code ?? 'UMUM';
        }

        return DB::transaction(function () use ($intern, $divisionCode, $brandStr, $romanMonth, $year) {
            // Kunci baris terakhir untuk bulan/tahun ini
            $last = SklDocument::where('skl_number', 'LIKE', "%/SKL/%/{$romanMonth}/{$year}")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $seq = 1;
            if ($last && preg_match('/^(\d{3})\/SKL\//', $last->skl_number, $m)) {
                $seq = (int)$m[1] + 1;
            }

            // Loop verifikasi keunikan nomor jika sudah ada di DB
            do {
                $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                $candidateNumber = "{$seqStr}/SKL/{$divisionCode}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
                $exists = SklDocument::where('skl_number', $candidateNumber)->exists();
                if ($exists) {
                    $seq++;
                }
            } while ($exists);

            return $candidateNumber;
        });
    }
}
