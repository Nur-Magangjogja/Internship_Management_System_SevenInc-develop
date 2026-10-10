<?php

namespace App\Services;

use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\RegistrationContactLink;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ContactMatchingService
{
    /**
     * Daftar frasa placeholder yang bukan kontak/pembimbing riil.
     */
    public const PLACEHOLDERS = [
        '',
        '-',
        '--',
        '---',
        'belum ada',
        'tidak ada',
        'belum dapat dosen pembimbing',
        'belum dapat guru pembimbing',
        'belum dapat pembimbing',
        'belum tahu',
        'tidak tahu',
        'none',
        'n/a',
        'na',
        'null',
    ];

    /**
     * Cek apakah sebuah teks adalah placeholder atau kosong.
     */
    public static function isPlaceholder(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return true;
        }

        $lower = mb_strtolower($trimmed);
        if (in_array($lower, self::PLACEHOLDERS, true)) {
            return true;
        }

        // Cek jika hanya berisi tanda baca atau angka berulang seperti strip
        $stripped = preg_replace('/[.,\-_()\/]/', '', $lower);
        if (trim($stripped) === '') {
            return true;
        }

        return false;
    }

    /**
     * Temukan kontak kanonikal yang memenuhi syarat auto-link sesuai Guardrail 1:
     * - same institution;
     * - is_verified = true;
     * - is_active = true;
     * - normalized phone exact dan non-empty;
     * - hasil query canonical match tepat satu;
     * - jika raw supervisor name tersedia/usable, normalized name juga harus exact.
     *
     * Jika ada mismatch, duplicate phone, phone kosong, name-only match, atau fuzzy match:
     * RETURN NULL (tidak auto-link, serahkan ke antrean review HR/Admin).
     */
    public static function findAutoLinkableContact(
        int $institutionId,
        ?string $rawSupervisorName,
        ?string $rawSupervisorPhone
    ): ?InstitutionContact {
        if (!$institutionId) {
            return null;
        }

        // Phone wajib ada dan bukan placeholder
        if (self::isPlaceholder($rawSupervisorPhone)) {
            return null;
        }

        $normalizedPhone = InstitutionContact::normalizePhone($rawSupervisorPhone);
        if (empty($normalizedPhone)) {
            return null;
        }

        // Query kontak resmi yang aktif dan terverifikasi di institusi yang sama dengan nomor telepon sama persis
        $matches = InstitutionContact::where('institution_id', $institutionId)
            ->where('is_verified', true)
            ->where('is_active', true)
            ->where('normalized_phone', $normalizedPhone)
            ->get();

        // Guardrail 1: Hasil query canonical match harus tepat satu (bukan 0 dan bukan > 1)
        if ($matches->count() !== 1) {
            return null;
        }

        $candidate = $matches->first();

        // Jika raw supervisor name tersedia dan bukan placeholder, normalized name juga harus exact
        if (!self::isPlaceholder($rawSupervisorName)) {
            $normalizedName = InstitutionContact::normalizeName($rawSupervisorName);
            if ($normalizedName !== null && $normalizedName !== '') {
                if ($candidate->normalized_name !== $normalizedName) {
                    // Name mismatch: nomor telepon sama namun nama berbeda -> JANGAN auto-link!
                    return null;
                }
            }
        }

        return $candidate;
    }

    /**
     * Mencoba auto-link pada registrasi yang baru disimpan.
     * Tidak membuat InstitutionContact baru jika tidak cocok.
     * Mengembalikan RegistrationContactLink jika berhasil auto-link, atau null jika masuk antrean review.
     */
    public static function attemptAutoLink(InternshipRegistration $registration): ?RegistrationContactLink
    {
        if (empty($registration->institution_id)) {
            return null;
        }

        // Jika kedua field pembimbing adalah placeholder, jangan auto-link
        if (self::isPlaceholder($registration->supervisor_name) && self::isPlaceholder($registration->supervisor_contact)) {
            return null;
        }

        // Cek jika sudah memiliki link aktif
        $existingLink = RegistrationContactLink::where('internship_registration_id', $registration->id)->first();
        if ($existingLink) {
            return $existingLink;
        }

        $canonicalContact = self::findAutoLinkableContact(
            $registration->institution_id,
            $registration->supervisor_name,
            $registration->supervisor_contact
        );

        if (!$canonicalContact) {
            return null;
        }

        // Auto-link exact dan aman
        return RegistrationContactLink::updateOrCreate(
            [
                'internship_registration_id' => $registration->id,
                'institution_contact_id'     => $canonicalContact->id,
            ],
            [
                'relation_year' => (int) date('Y'),
                'is_primary'    => true,
                'source'        => 'system_auto_link',
                'confirmed_by'  => null, // True system auto-link: tidak memalsukan user konfirmasi
                'confirmed_at'  => null,
            ]
        );
    }

    /**
     * Query antrean review pembimbing (HR / Admin Review Queue).
     * Sumber: internship_registrations yang memiliki input pembimbing riil (bukan placeholder)
     * dan belum memiliki link kontak terhubung.
     */
    public static function getReviewQueueQuery(): Builder
    {
        return InternshipRegistration::query()
            ->with(['institution', 'user', 'division'])
            ->whereDoesntHave('contactLinks')
            ->where(function (Builder $q) {
                $q->where(function ($sub) {
                    $sub->whereNotNull('supervisor_name')
                        ->whereNotIn(DB::raw('LOWER(TRIM(supervisor_name))'), self::PLACEHOLDERS);
                })->orWhere(function ($sub) {
                    $sub->whereNotNull('supervisor_contact')
                        ->whereNotIn(DB::raw('LOWER(TRIM(supervisor_contact))'), self::PLACEHOLDERS);
                });
            })
            ->latest('id');
    }

    /**
     * Menautkan pendaftaran ke kontak kanonikal yang sudah ada melalui aksi manual HR / Admin.
     */
    public static function linkExisting(
        InternshipRegistration $registration,
        int $contactId,
        int $actorId
    ): RegistrationContactLink {
        $contact = InstitutionContact::where('id', $contactId)
            ->where('institution_id', $registration->institution_id)
            ->firstOrFail();

        // Pastikan kontak terverifikasi
        if (!$contact->is_verified) {
            $contact->update([
                'is_verified' => true,
                'verified_by' => $actorId,
                'verified_at' => now(),
            ]);
        }

        $link = RegistrationContactLink::updateOrCreate(
            [
                'internship_registration_id' => $registration->id,
                'institution_contact_id'     => $contact->id,
            ],
            [
                'relation_year' => (int) date('Y'),
                'is_primary'    => true,
                'source'        => 'manual_review',
                'confirmed_by'  => $actorId,
                'confirmed_at'  => now(),
            ]
        );

        $actor = User::find($actorId);

        ActivityLogger::log(
            action: 'institution_contact_linked',
            description: 'Menautkan pembimbing resmi: ' . $contact->name . ' ke pendaftaran ' . ($registration->fullname ?? $registration->id),
            subject: $link,
            after: [
                'registration_id' => $registration->id,
                'contact_id'      => $contact->id,
                'confirmed_by'    => $actorId,
            ],
            actor: $actor
        );

        return $link;
    }

    /**
     * Membuat kontak kanonikal baru dari hasil review HR / Admin, sekaligus memverifikasinya.
     */
    public static function createAndVerify(
        InternshipRegistration $registration,
        array $contactData,
        int $actorId
    ): RegistrationContactLink {
        $actor = User::find($actorId);

        $contact = InstitutionContact::create([
            'institution_id' => $registration->institution_id,
            'name'           => trim($contactData['name']),
            'position_title' => !empty($contactData['position_title']) ? trim($contactData['position_title']) : null,
            'phone'          => !empty($contactData['phone']) ? trim($contactData['phone']) : null,
            'email'          => !empty($contactData['email']) ? trim($contactData['email']) : null,
            'notes'          => !empty($contactData['notes']) ? trim($contactData['notes']) : 'Dibuat & diverifikasi melalui Review Kontak Pembimbing',
            'is_active'      => true,
            'is_verified'    => true,
            'verified_by'    => $actorId,
            'verified_at'    => now(),
        ]);

        ActivityLogger::log(
            action: 'institution_contact_created',
            description: 'Menambahkan & memverifikasi kontak pembimbing: ' . $contact->name,
            subject: $contact,
            after: $contact->only(['name', 'position_title', 'phone', 'email', 'is_verified']),
            actor: $actor
        );

        $link = RegistrationContactLink::create([
            'internship_registration_id' => $registration->id,
            'institution_contact_id'     => $contact->id,
            'relation_year'              => (int) date('Y'),
            'is_primary'                 => true,
            'source'                     => 'manual_review',
            'confirmed_by'               => $actorId,
            'confirmed_at'               => now(),
        ]);

        ActivityLogger::log(
            action: 'institution_contact_linked',
            description: 'Menautkan pembimbing baru terverifikasi: ' . $contact->name . ' ke pendaftaran ' . ($registration->fullname ?? $registration->id),
            subject: $link,
            after: [
                'registration_id' => $registration->id,
                'contact_id'      => $contact->id,
                'confirmed_by'    => $actorId,
            ],
            actor: $actor
        );

        return $link;
    }
}
