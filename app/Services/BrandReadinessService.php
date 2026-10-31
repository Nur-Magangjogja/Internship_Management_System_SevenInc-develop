<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Division;
use App\Models\SpreadsheetConnection;

class BrandReadinessService
{
    /** @return array{ready: bool, blockers: array<int, string>} */
    public function assess(Brand $brand): array
    {
        $blockers = [];

        if (empty($brand->registration_host) || empty($brand->primary_domain)) {
            $blockers[] = 'Host pendaftaran dan domain utama wajib diisi.';
        } else {
            try {
                $domainInput = app(BrandDomainInputService::class);
                $normalizedHost = $domainInput->normalize((string) $brand->registration_host);
                $normalizedDomain = $domainInput->normalize((string) $brand->primary_domain);
                if ($normalizedHost !== $brand->registration_host || $normalizedDomain !== $brand->primary_domain) {
                    $blockers[] = 'Host/domain harus tersimpan dalam format normalisasi lowercase tanpa protokol atau path.';
                }
                if (!$domainInput->registrationHostBelongsToPrimaryDomain($normalizedHost, $normalizedDomain)) {
                    $blockers[] = 'Host pendaftaran bukan bagian dari domain utama yang terdaftar.';
                }
            } catch (\InvalidArgumentException) {
                $blockers[] = 'Format host pendaftaran atau domain utama tidak valid.';
            }
        }

        if (!$brand->domain_verified_at) {
            $blockers[] = 'Kepemilikan domain belum diverifikasi melalui DNS TXT.';
        }

        if (!in_array($brand->division_mode, [null, Brand::DIVISION_MODE_GLOBAL, Brand::DIVISION_MODE_BRAND_SPECIFIC], true)) {
            $blockers[] = 'Mode divisi tidak valid.';
        } elseif ($brand->division_mode === Brand::DIVISION_MODE_BRAND_SPECIFIC || ($brand->division_mode === null && $brand->divisions()->exists())) {
            $hasActiveDivision = $brand->divisions()
                ->where('divisions.is_active', true)
                ->exists();
            if (!$hasActiveDivision) {
                $blockers[] = 'Brand khusus belum memiliki divisi aktif yang dibuka.';
            }
        } elseif (!Division::active()->exists()) {
            $blockers[] = 'Belum ada divisi global aktif yang dapat dipilih.';
        }

        $connection = SpreadsheetConnection::query()
            ->where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->where('brand_id', $brand->id)
            ->first();

        if (!$connection) {
            $blockers[] = 'Koneksi spreadsheet pendaftaran khusus brand belum dibuat (koneksi spreadsheet wajib tersedia).';
        } else {
            if (!(bool) $connection->is_enabled) {
                $blockers[] = 'Koneksi spreadsheet pendaftaran belum diaktifkan (koneksi spreadsheet harus aktif).';
            }
            if ($connection->connection_status !== SpreadsheetConnection::STATUS_CONNECTED) {
                $blockers[] = 'Koneksi spreadsheet pendaftaran belum berstatus connected (koneksi spreadsheet belum terhubung).';
            }
            if (empty($connection->spreadsheet_url) || empty($connection->spreadsheet_id)) {
                $blockers[] = 'URL atau ID spreadsheet pendaftaran belum valid.';
            }
            if (empty($connection->drive_folder_url) || empty($connection->drive_folder_id)) {
                $blockers[] = 'Folder Google Drive khusus brand belum dikonfigurasi.';
            }
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }
}
