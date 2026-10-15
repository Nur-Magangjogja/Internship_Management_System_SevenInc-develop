<?php

namespace App\Console\Commands;

use App\Models\RegistrationAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MigrateLegacyRegistrationDocumentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sertif:migrate-legacy-documents
                            {--execute : Jalankan eksekusi pemindahan nyata (default: dry-run / simulasi)}
                            {--force : Lewati konfirmasi interaktif saat eksekusi nyata}
                            {--dry-run : Jalankan mode simulasi tanpa perubahan (default)}
                            {--delete-source : Hapus berkas publik lama setelah verifikasi sukses (hanya aktif jika --execute)}
                            {--cleanup-only : Jalankan pembersihan berkas publik lama berdasarkan manifest migrasi}
                            {--limit=50 : Batas maksimal dokumen yang diproses per batch}';

    /**
     * The command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = ['registrations:migrate-legacy-documents'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrasikan berkas lampiran pendaftaran legacy dari public disk ke private local storage dengan verifikasi integritas checksum SHA256 dan manifest inventaris terverifikasi';

    /**
     * Jalur penyimpanan manifest inventaris migrasi pada disk privat lokal.
     */
    public const MANIFEST_PATH = 'private/migration_inventory/legacy_documents_manifest.json';

    /**
     * Daftar jenis lampiran sensitif yang diizinkan untuk dimigrasikan ke private storage.
     * Foto profil (profile_photo) secara ketat dikecualikan karena digunakan untuk display publik/avatar.
     */
    public const SENSITIVE_ATTACHMENT_TYPES = [
        'cv_ktp_portofolio',
        'cv_ktp_portofolio_pdf',
        'cv',
        'ktp',
        'portofolio',
        'document',
        'recommendation_letter',
        'identity',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hasExecute   = (bool) $this->option('execute');
        $isDryRun     = !$hasExecute || (bool) $this->option('dry-run');
        $force        = (bool) $this->option('force');
        $deleteSrc    = (bool) $this->option('delete-source') && !$isDryRun;
        $cleanupOnly  = (bool) $this->option('cleanup-only');
        $limit        = max(1, (int) $this->option('limit'));

        $this->info("=== UTILITY MIGRASI DOKUMEN REGISTRASI LEGACY (S17.18G.5 Wave 1R) ===");

        if ($isDryRun) {
            $this->warn("MODE: READ-ONLY / DRY-RUN (Simulasi) — Tidak ada berkas yang ditulis atau dihapus.");
            $this->line("Catatan: Gunakan flag --execute untuk menjalankan perubahan nyata.");
        } else {
            $this->alert("MODE: EKSEKUSI PERUBAHAN NYATA");
            if ($deleteSrc || $cleanupOnly) {
                $this->warn("PERINGATAN: Opsi pembersihan berkas publik lama aktif.");
            } else {
                $this->line("Info: Berkas publik lama akan tetap dipertahankan (copy-and-preserve mode).");
            }

            if (!$force && !$this->confirm("Apakah Anda yakin ingin mengeksekusi operasi dokumen fisik secara nyata?")) {
                $this->warn("Operasi dibatalkan oleh operator.");
                return 0;
            }
        }

        // Jika mode cleanup-only aktif, langsung jalankan pembersihan dari manifest
        if ($cleanupOnly) {
            return $this->processCleanupFromManifest($isDryRun);
        }

        $this->line("Batas batch: {$limit} record\n");

        // Filter: Hanya berkas yang belum privat, bukan foto profil, dan termasuk tipe dokumen sensitif (Fix A.4 & A.5)
        $attachments = RegistrationAttachment::where('file_path', 'not like', 'private/%')
            ->where('attachment_type', '!=', 'profile_photo')
            ->whereIn('attachment_type', self::SENSITIVE_ATTACHMENT_TYPES)
            ->limit($limit)
            ->get();

        $manifest = $this->loadManifest();

        if ($attachments->isEmpty()) {
            $this->info("Tidak ada dokumen legacy pada public disk yang memenuhi kriteria migrasi.");

            // Jika opsi delete-source diberikan pada berkas yang sebelumnya sudah dimigrasikan, lakukan pembersihan via manifest
            if ($this->option('delete-source')) {
                $this->info("Memeriksa manifest inventaris untuk pembersihan berkas sumber publik legacy yang telah dimigrasikan...");
                return $this->processCleanupFromManifest($isDryRun);
            }

            return 0;
        }

        $migratedCount = 0;
        $skippedCount  = 0;
        $failedCount   = 0;

        foreach ($attachments as $attachment) {
            $publicPath = $attachment->file_path;

            // 1. Validasi keamanan path terhadap path traversal (Fix A.11)
            if (empty($publicPath) || str_contains($publicPath, '..') || str_starts_with($publicPath, '/') || str_starts_with($publicPath, '\\')) {
                $this->warn("[SKIP] ID #{$attachment->id} memiliki path traversal tidak aman: '{$publicPath}'");
                $skippedCount++;
                continue;
            }

            // 2. Periksa keberadaan fisik pada disk public (Fix A.8 & A.11)
            if (!Storage::disk('public')->exists($publicPath)) {
                $this->warn("[SKIP] ID #{$attachment->id} berkas fisik tidak ditemukan di public disk: '{$publicPath}'");
                $skippedCount++;
                continue;
            }

            $sourceContents = Storage::disk('public')->get($publicPath);
            $sourceChecksum = hash('sha256', $sourceContents);
            $sourceSize     = strlen($sourceContents);

            // 3. Tentukan target path privat yang unik dan bebas collision (Fix A.6)
            $privatePath = $this->generateUniqueDestinationPath($attachment, $publicPath);

            // 4. Periksa apakah target destination sudah ada sebelumnya (Fix A.7 - Idempotency & Collision check)
            if (Storage::disk('local')->exists($privatePath)) {
                $existingContents = Storage::disk('local')->get($privatePath);
                $existingChecksum = hash('sha256', $existingContents);

                if ($existingChecksum === $sourceChecksum) {
                    // Berkas sudah ada dengan checksum identik
                    if ($isDryRun) {
                        $this->line("[DRY-RUN IDENTICAL] ID #{$attachment->id}: Target '{$privatePath}' sudah ada dengan checksum identik.");
                        $migratedCount++;
                        continue;
                    }

                    // Perbarui pointer DB dan manifest jika belum terupdate
                    try {
                        $attachment->update(['file_path' => $privatePath]);
                        $manifest[$attachment->id] = [
                            'attachment_id'              => $attachment->id,
                            'internship_registration_id' => $attachment->internship_registration_id,
                            'attachment_type'            => $attachment->attachment_type,
                            'original_source_path'       => $publicPath,
                            'private_destination_path'   => $privatePath,
                            'checksum_sha256'            => $sourceChecksum,
                            'file_size'                  => $sourceSize,
                            'migrated_at'                => now()->toIso8601String(),
                            'cleaned_at'                 => $deleteSrc ? now()->toIso8601String() : null,
                            'status'                     => $deleteSrc ? 'cleaned' : 'migrated',
                        ];
                        $this->saveManifest($manifest);

                        if ($deleteSrc) {
                            try {
                                Storage::disk('public')->delete($publicPath);
                            } catch (Throwable) {}
                        }

                        $this->info("[IDEMPOTENT] ID #{$attachment->id} disinkronkan ke target eksisting '{$privatePath}'.");
                        $migratedCount++;
                    } catch (Throwable $e) {
                        $this->error("[ERROR] ID #{$attachment->id} gagal memperbarui database: " . $e->getMessage());
                        $failedCount++;
                    }
                    continue;
                } else {
                    // Checksum berbeda: bahaya tabrakan berkas! Jangan timpa berkas existing.
                    $this->error("[COLLISION CONFLICT] ID #{$attachment->id}: Target '{$privatePath}' sudah ada dengan konten berbeda. Operasi dilewati demi keamanan.");
                    $failedCount++;
                    continue;
                }
            }

            // 5. Mode Dry-run: laporkan rencana tanpa menulis atau menghapus apapun (Fix A.1)
            if ($isDryRun) {
                $this->line("[DRY-RUN] ID #{$attachment->id} ({$attachment->attachment_type}): '{$publicPath}' -> '{$privatePath}' ({$sourceSize} bytes, SHA256: " . substr($sourceChecksum, 0, 8) . "...)");
                $migratedCount++;
                continue;
            }

            // 6. Eksekusi nyata terpisah: Copy -> Verify -> DB Update -> Manifest -> Optional Cleanup (Fix A.8, A.9, A.10, Issue 1)
            $copySucceeded = false;
            try {
                // A. Tulis ke disk privat lokal
                $written = Storage::disk('local')->put($privatePath, $sourceContents);
                if (!$written) {
                    throw new \RuntimeException("Gagal menulis berkas ke target privat lokal.");
                }

                // B. Verifikasi checksum salinan
                $targetContents = Storage::disk('local')->get($privatePath);
                $targetChecksum = hash('sha256', $targetContents);

                if ($targetChecksum !== $sourceChecksum) {
                    throw new \RuntimeException("Checksum mismatch: berkas hasil salinan tidak identik dengan sumber.");
                }

                $copySucceeded = true;

                // C. Update database dalam transaksi atomik
                DB::beginTransaction();
                $attachment->update([
                    'file_path' => $privatePath,
                ]);
                DB::commit();

                // D. Catat ke manifest inventaris migrasi terverifikasi (Issue 1)
                $manifest[$attachment->id] = [
                    'attachment_id'              => $attachment->id,
                    'internship_registration_id' => $attachment->internship_registration_id,
                    'attachment_type'            => $attachment->attachment_type,
                    'original_source_path'       => $publicPath,
                    'private_destination_path'   => $privatePath,
                    'checksum_sha256'            => $sourceChecksum,
                    'file_size'                  => $sourceSize,
                    'migrated_at'                => now()->toIso8601String(),
                    'cleaned_at'                 => null,
                    'status'                     => 'migrated',
                ];

                // E. Penghapusan sumber publik HANYA jika diminta eksplisit lewat --delete-source
                if ($deleteSrc) {
                    try {
                        Storage::disk('public')->delete($publicPath);
                        $manifest[$attachment->id]['status'] = 'cleaned';
                        $manifest[$attachment->id]['cleaned_at'] = now()->toIso8601String();
                    } catch (Throwable $cleanupEx) {
                        $this->warn("[CLEANUP WARNING] ID #{$attachment->id}: Gagal menghapus berkas publik lama ('{$publicPath}'): " . $cleanupEx->getMessage());
                    }
                }

                $this->saveManifest($manifest);

                $this->info("[SUCCESS] ID #{$attachment->id} berhasil dimigrasikan ke '{$privatePath}'.");
                $migratedCount++;
            } catch (Throwable $e) {
                // Rollback database jika sedang dalam transaksi
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                // Jika copy sempat dibuat tapi update DB gagal, bersihkan file privat baru agar tidak meninggalkan orphan
                if ($copySucceeded && Storage::disk('local')->exists($privatePath)) {
                    try {
                        Storage::disk('local')->delete($privatePath);
                    } catch (Throwable) {}
                }

                $this->error("[ERROR] ID #{$attachment->id} gagal dimigrasikan: " . $e->getMessage());
                $failedCount++;
            }
        }

        $this->newLine();
        $this->table(
            ['Total Ditemukan', 'Dimigrasikan / Layak', 'Dilewati (Missing/Invalid)', 'Gagal / Konflik'],
            [[$attachments->count(), $migratedCount, $skippedCount, $failedCount]]
        );

        // Jika opsi delete-source diberikan bersamaan, periksa juga apakah ada file di manifest dari batch sebelumnya
        if ($this->option('delete-source') && !$isDryRun) {
            $this->newLine();
            $this->info("Menjalankan pembersihan berkas publik yang tercatat pada manifest...");
            $this->processCleanupFromManifest(false);
        }

        return $failedCount > 0 ? 1 : 0;
    }

    /**
     * Proses pembersihan berkas publik lama secara aman menggunakan manifest inventaris terverifikasi (Issue 1).
     */
    public function processCleanupFromManifest(bool $isDryRun): int
    {
        $manifest = $this->loadManifest();
        $pendingCleanup = array_filter($manifest, function ($entry) {
            return ($entry['status'] ?? '') === 'migrated' && empty($entry['cleaned_at']);
        });

        // Audit fail-closed: Periksa apakah ada record privat yang tidak memiliki entri manifest
        $privatizedWithoutManifest = RegistrationAttachment::where('file_path', 'like', 'private/%')
            ->whereNotIn('id', array_keys($manifest))
            ->get();

        if ($privatizedWithoutManifest->isNotEmpty()) {
            foreach ($privatizedWithoutManifest as $untracked) {
                $this->warn("[FAIL-CLOSED UNKNOWN SOURCE] Attachment #{$untracked->id}: Berkas privat tidak memiliki rekaman manifest sumber publik. Path sumber tidak dapat ditebak. Memerlukan audit manual operator.");
            }
        }

        if (empty($pendingCleanup)) {
            $this->info("Manifest migrasi tidak memuat berkas publik yang perlu dibersihkan (semua sudah bersih atau belum ada berkas termigrasi).");
            return 0;
        }

        $this->info("Ditemukan " . count($pendingCleanup) . " berkas pada manifest yang siap dibersihkan dari storage publik.");

        $cleanedCount = 0;
        $blockedCount = 0;

        foreach ($pendingCleanup as $attId => $entry) {
            $attachment  = RegistrationAttachment::find($attId);
            $privatePath = $entry['private_destination_path'] ?? '';
            $publicPath  = $entry['original_source_path'] ?? '';
            $expectedSha = $entry['checksum_sha256'] ?? '';

            // 1. Validasi referensi DB (pointer harus sesuai private path di manifest)
            if (!$attachment || $attachment->file_path !== $privatePath) {
                $this->warn("[BLOCKED DB MISMATCH] Attachment #{$attId}: Pointer database ('" . ($attachment?->file_path ?? 'null') . "') tidak cocok dengan manifest ('{$privatePath}'). Pembersihan dibatalkan (fail-closed).");
                $blockedCount++;
                continue;
            }

            // 2. Validasi bahwa profile_photo TIDAK PERNAH dibersihkan
            if (($entry['attachment_type'] ?? '') === 'profile_photo' || $attachment->attachment_type === 'profile_photo') {
                $this->warn("[BLOCKED PROFILE PHOTO] Attachment #{$attId} adalah foto profil publik. Pembersihan dibatalkan.");
                $blockedCount++;
                continue;
            }

            // 3. Validasi fisik berkas privat lokal dan kecocokan checksum
            if (!Storage::disk('local')->exists($privatePath)) {
                $this->error("[BLOCKED MISSING PRIVATE] Attachment #{$attId}: Berkas privat lokal '{$privatePath}' tidak ditemukan. Berkas publik '{$publicPath}' dipertahankan (fail-closed).");
                $blockedCount++;
                continue;
            }

            $currentPrivateSha = hash('sha256', Storage::disk('local')->get($privatePath));
            if ($currentPrivateSha !== $expectedSha) {
                $this->error("[BLOCKED CHECKSUM MISMATCH] Attachment #{$attId}: Checksum berkas privat lokal tidak cocok dengan manifest. Berkas publik '{$publicPath}' dipertahankan (fail-closed).");
                $blockedCount++;
                continue;
            }

            // 4. Validasi path traversal pada public source
            if (empty($publicPath) || str_contains($publicPath, '..') || str_starts_with($publicPath, '/') || str_starts_with($publicPath, '\\')) {
                $this->warn("[BLOCKED PATH TRAVERSAL] Attachment #{$attId}: Path sumber '{$publicPath}' tidak aman.");
                $blockedCount++;
                continue;
            }

            // 5. Cek apakah berkas publik masih ada
            if (!Storage::disk('public')->exists($publicPath)) {
                $this->line("[ALREADY CLEANED] Attachment #{$attId}: Berkas publik '{$publicPath}' sudah tidak ada.");
                $manifest[$attId]['status'] = 'cleaned';
                $manifest[$attId]['cleaned_at'] = now()->toIso8601String();
                $cleanedCount++;
                continue;
            }

            // 6. Validasi bahwa checksum berkas publik masih cocok dengan expected
            $currentPublicSha = hash('sha256', Storage::disk('public')->get($publicPath));
            if ($currentPublicSha !== $expectedSha) {
                $this->warn("[BLOCKED PUBLIC HASH MISMATCH] Attachment #{$attId}: Checksum berkas publik berbeda dari expected sha. Berkas publik dipertahankan.");
                $blockedCount++;
                continue;
            }

            if ($isDryRun) {
                $this->line("[DRY-RUN CLEANUP] Attachment #{$attId}: Berkas publik '{$publicPath}' akan dihapus (terverifikasi identik dengan berkas privat).");
                $cleanedCount++;
                continue;
            }

            // Eksekusi penghapusan nyata
            try {
                Storage::disk('public')->delete($publicPath);
                $manifest[$attId]['status'] = 'cleaned';
                $manifest[$attId]['cleaned_at'] = now()->toIso8601String();
                $this->info("[CLEANUP SUCCESS] Attachment #{$attId}: Berkas publik '{$publicPath}' berhasil dihapus.");
                $cleanedCount++;
            } catch (Throwable $e) {
                $this->error("[CLEANUP FAILED] Attachment #{$attId}: Gagal menghapus berkas publik '{$publicPath}': " . $e->getMessage());
                $blockedCount++;
            }
        }

        if (!$isDryRun) {
            $this->saveManifest($manifest);
        }

        $this->newLine();
        $this->table(
            ['Pembersihan Berhasil / Layak', 'Diblokir / Gagal (Fail-Closed)'],
            [[$cleanedCount, $blockedCount]]
        );

        return $blockedCount > 0 ? 1 : 0;
    }

    /**
     * Memuat manifest inventaris migrasi dari disk privat lokal.
     */
    public function loadManifest(): array
    {
        if (Storage::disk('local')->exists(self::MANIFEST_PATH)) {
            $raw = Storage::disk('local')->get(self::MANIFEST_PATH);
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    /**
     * Menyimpan manifest inventaris migrasi ke disk privat lokal.
     */
    public function saveManifest(array $manifest): void
    {
        Storage::disk('local')->put(
            self::MANIFEST_PATH,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Buat jalur tujuan unik berbasis registrasi dan attachment ID untuk mencegah tabrakan nama berkas (Fix A.6).
     */
    public function generateUniqueDestinationPath(RegistrationAttachment $attachment, string $sourcePath): string
    {
        $regId    = $attachment->internship_registration_id ?: 'unlinked';
        $attId    = $attachment->id;
        $basename = basename($sourcePath);
        $ext      = pathinfo($basename, PATHINFO_EXTENSION);
        $nameOnly = pathinfo($basename, PATHINFO_FILENAME);
        $safeName = Str::slug($nameOnly, '_');
        if (empty($safeName)) {
            $safeName = 'attachment';
        }

        return "private/registrations/{$regId}/att_{$attId}_{$safeName}.{$ext}";
    }
}


