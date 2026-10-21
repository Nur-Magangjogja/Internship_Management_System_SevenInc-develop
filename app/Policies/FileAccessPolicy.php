<?php

namespace App\Policies;

use App\Models\CloudFileRegistry;
use App\Models\User;

class FileAccessPolicy
{
    /**
     * Tentukan apakah pengguna berhak melihat/pratinjau berkas registry.
     */
    public function view(User $user, CloudFileRegistry $file): bool
    {
        // 1. Superadmin memiliki hak akses penuh
        if ($user->role === 'superadmin') {
            return true;
        }

        // 2. Staf Admin / HR / Koordinator memiliki akses administratif
        if (in_array($user->role, ['admin', 'hr_staff', 'coordinator', 'super_admin'], true)) {
            return true;
        }

        // 3. Hak akses Pemagang: HANYA untuk berkas miliknya sendiri (Strict Isolation)
        $userRegId = $user->registration_id ?? $user->registration?->id ?? null;

        if ($file->owner_type === 'registration') {
            return !empty($userRegId) && (int) $file->owner_id === (int) $userRegId;
        }

        if ($file->owner_type === 'user') {
            return (int) $file->owner_id === (int) $user->id;
        }

        // Aset brand/institusi/sistem publik dapat dilihat jika diperlukan
        if (in_array($file->category, [
            CloudFileRegistry::CATEGORY_BRAND_LOGO,
            CloudFileRegistry::CATEGORY_INSTITUTION_LOGO,
        ], true)) {
            return true;
        }

        // Berkas milik orang lain atau kategori privat lainnya ditolak fail-closed
        return false;
    }

    /**
     * Tentukan apakah pengguna berhak mengunduh berkas registry.
     */
    public function download(User $user, CloudFileRegistry $file): bool
    {
        return $this->view($user, $file);
    }
}
