<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add an explicit activation lifecycle and a DNS TXT ownership-verification record.
     * Existing official static hosts are grandfathered because they were already in the
     * application's exact TrustHosts allowlist before this migration.
     */
    public function up(): void
    {
        if (!Schema::hasTable('brands')) {
            return;
        }

        Schema::table('brands', function (Blueprint $table): void {
            if (!Schema::hasColumn('brands', 'lifecycle_status')) {
                $table->string('lifecycle_status', 32)->default('configuration_pending')->index();
            }
            if (!Schema::hasColumn('brands', 'domain_verification_token')) {
                $table->string('domain_verification_token', 120)->nullable();
            }
            if (!Schema::hasColumn('brands', 'domain_verified_at')) {
                $table->timestamp('domain_verified_at')->nullable();
            }
            if (!Schema::hasColumn('brands', 'domain_verification_method')) {
                $table->string('domain_verification_method', 40)->nullable();
            }
        });

        // Preserve existing production behavior only for the precise hosts that were
        // previously trusted statically. All other existing rows require re-verification.
        $legacyOfficialDomains = [
            ['code' => 'RW',  'registration_host' => 'daftar.republikweb.net', 'primary_domain' => 'republikweb.net'],
            ['code' => 'AKM', 'registration_host' => 'daftar.adakamar.id', 'primary_domain' => 'adakamar.id'],
            ['code' => 'MJ',  'registration_host' => 'daftar.magangjogja.com', 'primary_domain' => 'magangjogja.com'],
            ['code' => 'MK',  'registration_host' => 'daftar.magangku.com', 'primary_domain' => 'magangku.com'],
            ['code' => 'TT',  'registration_host' => 'daftar.tuantanah.com', 'primary_domain' => 'tuantanah.com'],
            ['code' => 'MO',  'registration_host' => 'daftar.makeover.id', 'primary_domain' => 'makeover.id'],
            ['code' => 'BC',  'registration_host' => 'daftar.bimbelcerdas.com', 'primary_domain' => 'bimbelcerdas.com'],
            ['code' => 'AK',  'registration_host' => 'daftar.areakerja.com', 'primary_domain' => 'areakerja.com'],
            ['code' => 'TL',  'registration_host' => 'daftar.tukanglas.org', 'primary_domain' => 'tukanglas.org'],
            ['code' => 'TS',  'registration_host' => 'daftar.titipsini.com', 'primary_domain' => 'titipsini.com'],
            ['code' => 'SI',  'registration_host' => 'daftar.seveninc.web.id', 'primary_domain' => 'seveninc.web.id'],
        ];

        $verifiedAt = now();
        foreach ($legacyOfficialDomains as $domain) {
            DB::table('brands')
                ->where('code', $domain['code'])
                ->where('registration_host', $domain['registration_host'])
                ->where('primary_domain', $domain['primary_domain'])
                ->whereNull('domain_verified_at')
                ->whereNull('domain_verification_token')
                ->update([
                    'lifecycle_status' => 'active',
                    'domain_verified_at' => $verifiedAt,
                    'domain_verification_method' => 'legacy_static_allowlist',
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('brands')) {
            return;
        }

        $dynamicRecordsExist = DB::table('brands')
            ->where(function ($query): void {
                $query->whereNotNull('domain_verification_token')
                    ->orWhereNotNull('domain_verified_at')
                    ->orWhere('lifecycle_status', '!=', 'active');
            })
            ->exists();

        if ($dynamicRecordsExist) {
            throw new RuntimeException(
                'Rollback lifecycle brand dibatalkan karena ada data verifikasi atau status dinamis. '
                . 'Ekspor/pulihkan data tersebut secara eksplisit sebelum menghapus kolom lifecycle.'
            );
        }

        Schema::table('brands', function (Blueprint $table): void {
            foreach (['domain_verification_method', 'domain_verified_at', 'domain_verification_token', 'lifecycle_status'] as $column) {
                if (Schema::hasColumn('brands', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
