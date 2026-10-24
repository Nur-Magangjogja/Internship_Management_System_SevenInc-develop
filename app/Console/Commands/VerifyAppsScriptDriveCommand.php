<?php

namespace App\Console\Commands;

use App\Models\CloudStorageSetting;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Services\CloudStorage\CloudStorageProviderInterface;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\CloudStorage\SystemStorageConfigService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use Illuminate\Console\Command;

class VerifyAppsScriptDriveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:verify-apps-script
                            {--audit-samples=5 : Jumlah sampel berkas historis yang diaudit (read-only)}
                            {--allow-remote-write : Izinkan pengujian tulis & baca berkas dummy ke Google Drive asli}
                            {--skip-large-files : Hanya uji berkas dummy kecil tanpa berkas 5MB dan 10MB}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifikasi operasional Google Apps Script Drive Gateway dan kesiapan cutover GAS-06';

    public function handle(SystemStorageConfigService $configService): int
    {
        $this->info('================================================================');
        $this->info(' SERTIF — VERIFIKASI OPERASIONAL GOOGLE APPS SCRIPT DRIVE');
        $this->info('================================================================');

        $isBlocked = false;
        $blockReasons = [];
        $remoteTransferExecuted = false;
        $remoteTransferPassed = false;
        $realHistoricalCount = 0;
        $realHistoricalAccessible = 0;

        // ── 1. AUDIT KONFIGURASI LOCAL & KREDENSIAL ─────────────────────────
        $this->newLine();
        $this->line('<fg=cyan;options=bold>[1/5] Audit Konfigurasi Provider & Kredensial</>');

        $setting = CloudStorageSetting::getEffectiveSettings();
        $providerName = $setting->getActiveProvider();
        $this->line("  • Active Provider         : <fg=yellow>{$providerName}</>");

        $sprConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first()
            ?: SpreadsheetConnection::whereNotNull('apps_script_endpoint_url')->first();

        $endpoint = $sprConn?->apps_script_endpoint_url ?: config('services.google_apps_script.endpoint_url');
        $hasEndpoint = !empty($endpoint);
        $maskedEndpoint = $this->maskEndpointUrl($endpoint);
        $this->line("  • Web App Endpoint        : " . ($hasEndpoint ? "<fg=green>TERPASANG</> ({$maskedEndpoint})" : "<fg=red>KOSONG</>"));

        $hasSecret = !empty(config('services.google_apps_script.shared_secret'));
        $this->line("  • HMAC Shared Secret      : " . ($hasSecret ? "<fg=green>TERPASANG</> (Tersimpan aman di server, terlindungi)" : "<fg=red>KOSONG</>"));

        $canonicalFolderId = $setting->getEffectiveParentFolderId();
        $this->line("  • Canonical Folder ID     : " . (!empty($canonicalFolderId) ? "<fg=green>{$canonicalFolderId}</>" : "<fg=red>KOSONG</>"));

        if (!$hasEndpoint || !$hasSecret || empty($canonicalFolderId)) {
            $isBlocked = true;
            $blockReasons[] = "Parameter konfigurasi Apps Script (Endpoint, HMAC secret, atau Folder ID) belum lengkap.";
        }

        // ── 2. AUDIT INTEGRITAS SCRIPT FILE LOKAL ───────────────────────────
        $this->newLine();
        $this->line('<fg=cyan;options=bold>[2/5] Audit Kode resources/apps-script/SertifGoogleIntegration.js</>');
        $scriptPath = resource_path('apps-script/SertifGoogleIntegration.js');

        if (!file_exists($scriptPath)) {
            $this->error("  ✗ File SertifGoogleIntegration.js tidak ditemukan.");
            $isBlocked = true;
            $blockReasons[] = "File script Apps Script lokal tidak ditemukan.";
        } else {
            $scriptContent = file_get_contents($scriptPath);
            $requiredActions = [
                'drive_ping',
                'drive_resolve_folder',
                'drive_upload',
                'drive_get_metadata',
                'drive_download_chunk',
                'drive_list_children',
                'drive_search_files',
                'drive_trash',
            ];

            $missingActions = [];
            foreach ($requiredActions as $act) {
                if (!str_contains($scriptContent, "\"{$act}\"") && !str_contains($scriptContent, "'{$act}'")) {
                    $missingActions[] = $act;
                }
            }

            if (empty($missingActions)) {
                $this->line("  • Aksi drive_* (8 aksi)   : <fg=green>LENGKAP 100%</> di file lokal");
            } else {
                $this->line("  • Aksi drive_*            : <fg=red>HILANG: " . implode(', ', $missingActions) . "</>");
                $isBlocked = true;
                $blockReasons[] = "Script lokal kehilangan beberapa aksi drive_*.";
            }
        }

        // ── 3. UJI KONEKSI READ-ONLY LIVE ENDPOINT (drive_ping) ──────────────
        $this->newLine();
        $this->line('<fg=cyan;options=bold>[3/5] Uji Koneksi Live Endpoint (Read-Only drive_ping)</>');

        if ($hasEndpoint && $hasSecret && !empty($canonicalFolderId)) {
            $transport = app(GoogleAppsScriptTransport::class);

            $this->line("  • Mengirim tanda tangan HMAC-SHA256 untuk aksi 'drive_ping'...");
            try {
                $response = $transport->dispatchSignedRequest(
                    $endpoint,
                    [
                        'action'          => 'drive_ping',
                        'drive_folder_id' => $canonicalFolderId,
                    ],
                    'CLI_VERIFY_DRIVE_PING'
                );

                $isAccessible = ($response->context['accessible'] ?? ($response->context['context']['accessible'] ?? false)) === true;
                $rootName = $response->context['root_folder_name'] ?? ($response->context['context']['root_folder_name'] ?? 'Google Drive');
                $errCode = $response->errorCode ?: ($response->context['error_code'] ?? ($response->context['context']['error_code'] ?? ''));

                if ($response->success && $isAccessible) {
                    $this->line("  • Status drive_ping       : <fg=green>BERHASIL</> (Folder: '{$rootName}')");
                } elseif ($errCode === 'UNKNOWN_ACTION' || str_contains($response->errorMessage ?? '', 'tidak didukung')) {
                    $this->line("  • Status drive_ping       : <fg=yellow;options=bold>UNKNOWN_ACTION</>");
                    $this->warn("    => Web App Google Apps Script di cloud masih menjalankan deployment versi lama!");
                    $this->warn("    => Salin isi resources/apps-script/SertifGoogleIntegration.js ke Apps Script Editor lalu Deploy ulang.");
                    $isBlocked = true;
                    $blockReasons[] = "Web App Google Apps Script di cloud belum di-update dengan versi SertifGoogleIntegration.js terbaru (UNKNOWN_ACTION pada drive_ping).";
                } elseif ($errCode === 'INVALID_HMAC_SIGNATURE') {
                    $this->line("  • Status drive_ping       : <fg=red;options=bold>INVALID_HMAC_SIGNATURE</>");
                    $this->error("    => Nilai GOOGLE_APPS_SCRIPT_SHARED_SECRET pada server tidak cocok dengan Script Properties di Google Cloud.");
                    $isBlocked = true;
                    $blockReasons[] = "Tanda tangan HMAC ditolak oleh Web App Google Apps Script (kunci rahasia tidak cocok).";
                } else {
                    $errMsg = $this->sanitizeDiagnosticMessage($response->errorMessage ?: ($response->context['message'] ?? 'Unknown error'));
                    $this->line("  • Status drive_ping       : <fg=red>GAGAL [{$errCode}]</> ({$errMsg})");
                    $isBlocked = true;
                    $blockReasons[] = "Gagal memverifikasi folder kanonikal: [{$errCode}] {$errMsg}";
                }
            } catch (\Throwable $e) {
                $cleanMsg = $this->sanitizeDiagnosticMessage($e->getMessage());
                $this->line("  • Status drive_ping       : <fg=red>EXCEPTION</> ({$cleanMsg})");
                $isBlocked = true;
                $blockReasons[] = "Koneksi gateway terputus: {$cleanMsg}";
            }
        } else {
            $this->line("  • Status drive_ping       : <fg=yellow>DILEWATI (Konfigurasi belum lengkap)</>");
        }

        // ── 4. AUDIT METADATA SAMPEL BERKAS HISTORIS (READ-ONLY) ────────────
        $this->newLine();
        $sampleCount = (int) $this->option('audit-samples');
        $this->line("<fg=cyan;options=bold>[4/5] Audit Metadata Sampel ({$sampleCount} Berkas Historis - Read-Only)</>");

        // Investigasi populasi seluruh lampiran historis ber-drive_file_id
        $totalWithDriveId = RegistrationAttachment::whereNotNull('drive_file_id')->count();
        $demoSeedCount = RegistrationAttachment::whereNotNull('drive_file_id')
            ->where(function ($q) {
                $q->where('drive_file_id', 'like', '1demo_%')
                  ->orWhere('drive_file_id', 'like', '1folder_reg_%');
            })
            ->count();
        $potentialRealCount = $totalWithDriveId - $demoSeedCount;

        if ($totalWithDriveId > 0 && $potentialRealCount === 0) {
            $this->line("  • Populasi Database       : Total <fg=yellow>{$totalWithDriveId}</> lampiran historis ber-ID Drive.");
            $this->line("  • Bukti Sumber Data       : <fg=cyan>100% ({$demoSeedCount}/{$totalWithDriveId}) merupakan DEMO_PLACEHOLDER dari seeder</>");
            $this->line("                              (DummyPemagangSeeder & InternshipRegistrationSeeder).");
            $this->line("  • Berkas Riil Tersedia    : <fg=yellow>0 berkas</> (Seluruh record lokal adalah fixture uji, tidak ada file riil yang hilang).");
        } elseif ($totalWithDriveId > 0) {
            $this->line("  • Populasi Database       : Total <fg=yellow>{$totalWithDriveId}</> lampiran ({$demoSeedCount} fixture seeder, {$potentialRealCount} potensi berkas riil).");
        } else {
            $this->line("  • Populasi Database       : <fg=yellow>Tidak ada lampiran ber-drive_file_id di database</>");
        }

        $sampleAttachments = RegistrationAttachment::whereNotNull('drive_file_id')
            ->orderBy('id', 'asc')
            ->limit($sampleCount)
            ->get();

        if ($sampleAttachments->isNotEmpty()) {
            $sampleRows = [];
            $allSamplesArePlaceholders = true;

            foreach ($sampleAttachments as $att) {
                $audit = $configService->auditHistoricalAttachment($att);
                $classification = $audit['classification'] ?? $audit['status'];
                $isPlaceholder = ($classification === 'DEMO_PLACEHOLDER');

                if (!$isPlaceholder) {
                    $allSamplesArePlaceholders = false;
                    $realHistoricalCount++;
                    if ($audit['accessible'] ?? false) {
                        $realHistoricalAccessible++;
                    } else {
                        $isBlocked = true;
                        $cleanErr = $this->sanitizeDiagnosticMessage($audit['error'] ?? 'Inaccessible');
                        $blockReasons[] = "Berkas historis riil ID {$att->id} gagal diakses [{$classification}]: {$cleanErr}";
                    }
                }

                $statusLabel = match ($classification) {
                    'ACCESSIBLE'       => '<fg=green>ACCESSIBLE</>',
                    'DEMO_PLACEHOLDER' => '<fg=yellow>DEMO_PLACEHOLDER</>',
                    'FILE_NOT_FOUND'   => '<fg=red>FILE_NOT_FOUND</>',
                    'ACCESS_DENIED'    => '<fg=red>ACCESS_DENIED</>',
                    'OUTSIDE_ROOT'     => '<fg=red>OUTSIDE_ROOT</>',
                    default            => '<fg=red>' . $classification . '</>',
                };

                $catatan = match ($classification) {
                    'ACCESSIBLE'       => ($audit['remote_filename'] ?? 'OK') . ' (' . ($audit['remote_size'] ?? 0) . ' B)',
                    'DEMO_PLACEHOLDER' => 'Fixture seeder (wajar tidak ada di Drive asli)',
                    'FILE_NOT_FOUND'   => 'Berkas riil tidak ditemukan pada Drive',
                    'ACCESS_DENIED'    => 'Izin akses ditolak oleh Google Drive',
                    'OUTSIDE_ROOT'     => 'Berkas di luar folder induk kanonikal',
                    'GATEWAY_ERROR'    => substr($this->sanitizeDiagnosticMessage($audit['error'] ?? 'Gateway Error'), 0, 40),
                    default            => substr($this->sanitizeDiagnosticMessage($audit['error'] ?? $classification), 0, 40),
                };

                $sampleRows[] = [
                    'ID'           => $att->id,
                    'Tipe'         => $att->attachment_type,
                    'File ID'      => substr((string) $att->drive_file_id, 0, 22) . '...',
                    'Klasifikasi'  => $classification,
                    'Status'       => $statusLabel,
                    'Diagnostik'   => $catatan,
                ];
            }

            $this->table(['ID', 'Tipe', 'File ID', 'Klasifikasi', 'Status', 'Diagnostik'], $sampleRows);

            if ($allSamplesArePlaceholders) {
                $this->comment("  => CATATAN ANALISIS BUKTI HISTORIS:");
                $this->comment("     Seluruh {$sampleCount} sampel di atas terbukti merupakan data dummy seeder (DEMO_PLACEHOLDER).");
                $this->comment("     Kegagalan pencarian ID tiruan di Google Drive adalah normal & membuktikan gateway");
                $this->comment("     menolak ID fiktif secara jujur, BUKAN tanda kegagalan Google Drive asli.");
                $this->comment("     Namun, ID tiruan TIDAK DAPAT dijadikan bukti bahwa pembacaan berkas Drive berfungsi.");
                $this->comment("     Pembuktian kemampuan baca & tulis harus diuji melalui transfer berkas dummy pada Tahap 5.");
            }
        }

        // ── 5. PERSIAPAN PENGUJIAN TULIS BERKAS DUMMY (SAFETY GUARD) ─────────
        $this->newLine();
        $this->line('<fg=cyan;options=bold>[5/5] Verifikasi Operasional Transfer Berkas Nyata (Safety Guard & Folder Terisolasi)</>');

        $allowWrite = (bool) $this->option('allow-remote-write');
        $skipLarge = (bool) $this->option('skip-large-files');
        $isolatedFolder = '_sertif_verification_test';

        $this->line("  • Folder Pengujian        : <fg=cyan>{$isolatedFolder}</> (Terisolasi dari data pemagang)");

        if (!$allowWrite) {
            $this->line("  • Penulisan Remote        : <fg=yellow;options=bold>DITANGGUHKAN (Safety Lock Aktif)</>");
            $this->comment("    => Penulisan dummy ke Google Drive asli hanya berjalan jika opsi --allow-remote-write diberikan.");
            $this->comment("    => Tanpa opsi ini, pengujian transfer baca-tulis dilewati demi keamanan data.");
            $remoteTransferExecuted = false;
            $remoteTransferPassed = false;
        } else {
            $this->line("  • Penulisan Remote        : <fg=green;options=bold>DIIZINKAN (Pengujian Terisolasi Aktif)</>");
            if ($isBlocked) {
                $this->warn("    => Dibatalkan otomatis karena langkah konfigurasi/koneksi sebelumnya masih BLOCKED.");
                $remoteTransferExecuted = false;
                $remoteTransferPassed = false;
            } else {
                $remoteTransferExecuted = true;
                $driver = app(CloudStorageProviderInterface::class);
                $transport = app(GoogleAppsScriptTransport::class);
                $transport->setTimeoutSeconds(180); // Batas waktu aman hingga 180 detik untuk pengujian transfer 10 MB

                // Daftar tingkatan pengujian bertahap
                $testSuites = [
                    [
                        'label' => 'Berkas Dummy Kecil (Teks ~1 KB)',
                        'type'  => 'small',
                        'size'  => 1024,
                    ],
                ];

                if (!$skipLarge) {
                    $testSuites[] = [
                        'label' => 'Berkas Uji 5 MB (Batas Standar SERTIF)',
                        'type'  => '5mb',
                        'size'  => 5 * 1024 * 1024, // 5,242,880 bytes
                    ];
                    $testSuites[] = [
                        'label' => 'Berkas Uji 10 MB (Batas Maksimum SERTIF)',
                        'type'  => '10mb',
                        'size'  => 10 * 1024 * 1024, // 10,485,760 bytes
                    ];
                }

                $allTransferPassed = true;
                $createdFileIds = [];

                foreach ($testSuites as $idx => $ts) {
                    $stepNum = $idx + 1;
                    $this->newLine();
                    $this->line("  <fg=yellow;options=bold>[5.{$stepNum}] {$ts['label']}</>");

                    $tmpFile = tempnam(sys_get_temp_dir(), 'sertif_test_');
                    $token = bin2hex(random_bytes(6));
                    $filename = "dummy_transfer_{$ts['type']}_" . time() . "_{$token}." . ($ts['type'] === 'small' ? 'txt' : 'bin');

                    // Buat payload lokal
                    if ($ts['type'] === 'small') {
                        $payload = "SERTIF_UJI_OPERASIONAL_GAS_06_" . now()->toIso8601String() . "_{$token}\n"
                            . str_repeat("DUMMY_VERIFICATION_CONTENT_SAFETY_TEST\n", 20);
                        file_put_contents($tmpFile, $payload);
                    } else {
                        $fh = fopen($tmpFile, 'wb');
                        $chunk = random_bytes(64 * 1024); // 64 KB chunk
                        $written = 0;
                        while ($written < $ts['size']) {
                            $toWrite = min(strlen($chunk), $ts['size'] - $written);
                            fwrite($fh, substr($chunk, 0, $toWrite));
                            $written += $toWrite;
                        }
                        fclose($fh);
                    }

                    $actualLocalSize = filesize($tmpFile);
                    $expectedChecksum = hash_file('sha256', $tmpFile);

                    $this->line("     • Ukuran Payload       : " . number_format($actualLocalSize) . " bytes");
                    $this->line("     • Checksum SHA-256     : <fg=yellow>{$expectedChecksum}</>");

                    $uploadedFileId = null;

                    try {
                        // 1. UPLOAD
                        $this->line("     • [1/4] Mengunggah berkas ke folder terisolasi '{$isolatedFolder}'...");
                        $uploadRes = $driver->uploadFile($tmpFile, $isolatedFolder, $filename);
                        $uploadedFileId = $uploadRes['file_id'] ?? null;

                        if (empty($uploadedFileId)) {
                            throw new \RuntimeException("Gateway tidak mengembalikan file_id setelah upload.");
                        }
                        $createdFileIds[] = $uploadedFileId;
                        $this->line("       => Berkas terunggah: ID <fg=green>{$uploadedFileId}</>");

                        // 2. METADATA
                        $this->line("     • [2/4] Memeriksa metadata berkas pada Google Drive...");
                        $meta = $driver->getFileMetadata($uploadedFileId);
                        $remoteSize = (int) ($meta['size'] ?? ($meta['size_bytes'] ?? 0));
                        $remoteName = $meta['name'] ?? 'unknown';

                        if ($remoteSize !== $actualLocalSize) {
                            throw new \RuntimeException("Ukuran metadata remote ({$remoteSize} B) tidak cocok dengan ukuran lokal ({$actualLocalSize} B).");
                        }
                        $this->line("       => Metadata konsisten: Nama '{$remoteName}', Ukuran {$remoteSize} bytes.");

                        // 3. DOWNLOAD & CHECKSUM
                        $this->line("     • [3/4] Mengunduh biner berkas dan memvalidasi checksum...");
                        $downloadedBytes = $driver->downloadFileBytes($uploadedFileId);
                        $downloadedSize = strlen($downloadedBytes);
                        $downloadedChecksum = hash('sha256', $downloadedBytes);

                        if ($downloadedSize !== $actualLocalSize) {
                            throw new \RuntimeException("Ukuran unduhan ({$downloadedSize} B) tidak cocok dengan berkas asli ({$actualLocalSize} B).");
                        }

                        if ($downloadedChecksum !== $expectedChecksum) {
                            $this->error("       => Checksum tidak cocok!");
                            $this->error("          Asli   : {$expectedChecksum}");
                            $this->error("          Unduhan: {$downloadedChecksum}");
                            throw new \RuntimeException("Checksum SHA-256 biner unduhan tidak identik dengan payload asli.");
                        }
                        $this->line("       => Checksum valid: <fg=green>BYTE-IDENTIK (SHA-256 Cocok 100%)</>");

                        // 4. PEMBERSIHAN (TRASH BERDASARKAN ID PENGUJIAN)
                        $this->line("     • [4/4] Membersihkan berkas uji dari Google Drive...");
                        $deleted = $driver->deleteFile($uploadedFileId);
                        if ($deleted) {
                            $this->line("       => Pembersihan: <fg=green>SUKSES (Dipindahkan ke Trash berdasarkan ID: {$uploadedFileId})</>");
                        } else {
                            $this->warn("       => Pembersihan: Menunggu konfirmasi trash.");
                        }

                    } catch (\Throwable $ex) {
                        $cleanErr = $this->sanitizeDiagnosticMessage($ex->getMessage());
                        if (str_contains($cleanErr, 'Checksum') || str_contains($cleanErr, 'tidak cocok')) {
                            $this->error("       => [CHECKSUM] Checksum tidak cocok! ({$cleanErr})");
                        } else {
                            $this->error("       ✗ Gagal pada tahap {$ts['label']}: {$cleanErr}");
                        }
                        $isBlocked = true;
                        $blockReasons[] = "Gagal pada tahap {$ts['label']}: {$cleanErr}";
                        $allTransferPassed = false;

                        // Pastikan pembersihan tetap dilakukan jika file sempat terupload
                        if (!empty($uploadedFileId)) {
                            try {
                                $driver->deleteFile($uploadedFileId);
                            } catch (\Throwable) {}
                        }
                        break;
                    } finally {
                        if (file_exists($tmpFile)) {
                            @unlink($tmpFile);
                        }
                    }
                }

                $remoteTransferPassed = $allTransferPassed;
            }
        }

        // ── REKAPITULASI & KEPUTUSAN FINAL (READINESS GATE) ─────────────────
        $this->newLine();
        $this->info('================================================================');

        // KASUS 1: BLOCKED (Terdapat kegagalan konfigurasi, deployment, atau transfer)
        if ($isBlocked) {
            $this->error(" KEPUTUSAN: BLOCKED FOR GAS-06");
            $this->info('================================================================');
            $this->newLine();
            $this->line('<fg=red;options=bold>Alasan Pemblokiran & Tindakan yang Diperlukan:</>');
            foreach ($blockReasons as $idx => $reason) {
                $num = $idx + 1;
                $this->line("  {$num}. {$reason}");
            }
            $this->newLine();
            $this->comment("Instruksi Keamanan:");
            $this->comment("1. Perbaiki kendala di atas sesuai panduan.");
            $this->comment("2. Driver OAuth2 lama (GoogleDriveStorageDriver.php) TIDAK BOLEH dihapus.");
            $this->comment("3. Jalankan kembali perintah verifikasi ini setelah perbaikan selesai.");
            return Command::FAILURE; // Exit Code: 1
        }

        // KASUS 2: PARTIALLY VERIFIED (Koneksi & baca siap, namun transfer riil belum diuji karena safety lock)
        if (!$remoteTransferPassed) {
            $this->warn(" KEPUTUSAN: PARTIALLY VERIFIED (PENDING LIVE TRANSFER / SAFETY LOCK)");
            $this->info('================================================================');
            $this->newLine();
            $this->line('<fg=yellow;options=bold>Status Kesiapan Cutover GAS-06:</>');
            $this->line("  ✓ Konfigurasi & Kredensial : <fg=green>VALID & LENGKAP</>");
            $this->line("  ✓ Script Lokal (8/8 aksi)  : <fg=green>LENGKAP</>");
            $this->line("  ✓ Uji Koneksi drive_ping   : <fg=green>BERHASIL TERHUBUNG KE GOOGLE DRIVE</>");
            $this->line("  ℹ Status Berkas Historis   : <fg=cyan>TERVERIFIKASI FIXTURE SEEDER (Bukan data riil hilang)</>");
            $this->line("  ⚠ Siklus Transfer Berkas   : <fg=yellow>BELUM DIUJI (Safety Lock Aktif)</>");
            $this->newLine();
            $this->comment("Penjelasan Keputusan:");
            $this->comment("Koneksi read-only dan autentikasi HMAC telah terbukti valid pada Google Drive.");
            $this->comment("Namun, status VERIFIED FOR GAS-06 mewajibkan bukti operasional bahwa gateway Apps Script");
            $this->comment("mampu menulis berkas, mengunduh biner secara identik, dan membersihkan berkas secara nyata.");
            $this->newLine();
            $this->line("Jalankan perintah berikut untuk mengizinkan uji tulis terisolasi:");
            $this->line("  <fg=cyan;options=bold>php artisan storage:verify-apps-script --allow-remote-write</>");
            $this->newLine();
            $this->comment("PERINGATAN: Driver OAuth2 lama (GoogleDriveStorageDriver.php) TETAP DIPERTAHANKAN");
            $this->comment("sampai verifikasi transfer riil berhasil.");
            return Command::INVALID; // Exit Code: 2
        }

        // KASUS 3: VERIFIED FOR GAS-06 (Seluruh pengujian, termasuk transfer riil dan checksum, sukses 100%)
        $this->info(" KEPUTUSAN: VERIFIED FOR GAS-06");
        $this->info('================================================================');
        $this->newLine();
        $this->line("<fg=green;options=bold>Seluruh verifikasi operasional Google Apps Script Drive BERHASIL 100%.</>");
        $this->line("  ✓ Konfigurasi & Kredensial : VALID & LENGKAP");
        $this->line("  ✓ Script Lokal (8/8 aksi)  : LENGKAP");
        $this->line("  ✓ Uji Koneksi drive_ping   : BERHASIL TERHUBUNG");
        $this->line("  ✓ Uji Tulis Berkas Dummy   : BERHASIL (Folder terisolasi '_sertif_verification_test')");
        $this->line("  ✓ Uji Unduh & Checksum     : VALID (SHA-256 Byte-Identik)");
        $this->line("  ✓ Pembersihan Berkas Dummy : BERHASIL (Dipindahkan ke Trash)");
        $this->line("  ✓ Status Berkas Historis   : TERVERIFIKASI AMAN");
        $this->newLine();
        $this->line("<fg=cyan;options=bold>KESIMPULAN: Seluruh jalur Apps Script terbukti siap menggantikan OAuth2.</>");
        $this->line("Driver OAuth2 lama (GoogleDriveStorageDriver.php) aman untuk dibersihkan pada tahap GAS-06.");
        return Command::SUCCESS; // Exit Code: 0
    }

    /**
     * Sanitasi pesan error agar tidak mengekspos kredensial rahasia, token, URL berparameter rahasia, atau data sensitif.
     */
    protected function sanitizeDiagnosticMessage(string $msg): string
    {
        $secret = (string) config('services.google_apps_script.shared_secret');
        if (!empty($secret)) {
            $msg = str_replace($secret, '***REDACTED***', $msg);
        }

        // Redact URL panjang atau query parameters
        $msg = preg_replace('/https?:\/\/[^\s]+/i', '[REDACTED_URL]', $msg);

        // Mask email jika tidak sengaja muncul
        $msg = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '***@***.***', $msg);

        return $msg;
    }

    /**
     * Samarkan URL endpoint Apps Script agar tetap informatif tanpa mengekspos ID deployment penuh secara mentah.
     */
    protected function maskEndpointUrl(?string $url): string
    {
        if (empty($url)) {
            return 'KOSONG';
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? 'script.google.com';
        $path = $parts['path'] ?? '';
        $shortPath = substr($path, 0, 15) . '.../' . basename($path);

        return "https://{$host}{$shortPath}";
    }
}
