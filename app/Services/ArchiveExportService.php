<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ArchiveExport;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class ArchiveExportService
{
    /**
     * Hitung preview jumlah data yang akan diekspor untuk tahun yang dipilih.
     */
    public function previewCounts(int $year): array
    {
        $internsCount = InternshipRegistration::query()
            ->where(function ($q) use ($year) {
                $q->whereYear('start_date', $year)
                  ->orWhereYear('created_at', $year);
            })
            ->count();

        $followupsCount = FollowUpCycle::query()
            ->where('cycle_year', $year)
            ->count();

        $mousCount = Mou::query()
            ->where(function ($q) use ($year) {
                $q->whereYear('agreement_date', $year)
                  ->orWhereYear('effective_start', $year)
                  ->orWhereYear('created_at', $year);
            })
            ->count();

        $contactsCount = InstitutionContact::query()
            ->whereYear('created_at', '<=', $year)
            ->where(function ($q) use ($year) {
                $q->whereYear('created_at', $year)
                  ->orWhereHas('followUpCycles', fn ($f) => $f->where('cycle_year', $year));
            })
            ->count();

        $institutionsCount = Institution::query()
            ->where(function ($q) use ($year) {
                $q->whereHas('followUpCycles', fn ($fc) => $fc->where('cycle_year', $year))
                  ->orWhereHas('mous', fn ($m) => $m->whereYear('agreement_date', $year)->orWhereYear('created_at', $year))
                  ->orWhereHas('internshipRegistrations', fn ($ir) => $ir->whereYear('start_date', $year)->orWhereYear('created_at', $year));
            })
            ->count();

        return [
            'year'               => $year,
            'interns_count'      => $internsCount,
            'contacts_count'     => $contactsCount,
            'followups_count'    => $followupsCount,
            'mous_count'         => $mousCount,
            'institutions_count' => $institutionsCount,
            'total_records'      => $internsCount + $contactsCount + $followupsCount + $mousCount,
        ];
    }

    /**
     * Generate paket arsip data tahunan dalam format ZIP berisi file CSV dengan UTF-8 BOM dan manifest.json.
     */
    public function generateArchive(int $year, ?User $actor = null): ArchiveExport
    {
        $counts = $this->previewCounts($year);
        $publicUuid = (string) Str::uuid();
        $timestamp = now()->format('Ymd_His');
        $zipFileName = "archive-seveninc-{$year}-{$timestamp}.zip";
        $relativeStorageDir = 'archives';
        $relativeStoragePath = "{$relativeStorageDir}/{$zipFileName}";

        // Pastikan direktori storage tujuan ada
        if (!Storage::disk('local')->exists($relativeStorageDir)) {
            Storage::disk('local')->makeDirectory($relativeStorageDir);
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'export_' . uniqid();
        if (!mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            throw new RuntimeException("Gagal membuat direktori temporary untuk ekspor arsip.");
        }

        try {
            $csvFiles = [];

            // 1. Ekspor Data Pemagang
            $internsFile = "pemagang-{$year}.csv";
            $this->writeInternsCsv($tempDir . DIRECTORY_SEPARATOR . $internsFile, $year);
            $csvFiles[] = $internsFile;

            // 2. Ekspor Kontak Guru & Dosen Pembimbing
            $contactsFile = "guru-dosen-{$year}.csv";
            $this->writeContactsCsv($tempDir . DIRECTORY_SEPARATOR . $contactsFile, $year);
            $csvFiles[] = $contactsFile;

            // 3. Ekspor Siklus Follow-Up
            $followupsFile = "follow-up-{$year}.csv";
            $this->writeFollowUpsCsv($tempDir . DIRECTORY_SEPARATOR . $followupsFile, $year);
            $csvFiles[] = $followupsFile;

            // 4. Ekspor Naskah & Status MOU
            $mousFile = "institusi-mou-{$year}.csv";
            $this->writeMousCsv($tempDir . DIRECTORY_SEPARATOR . $mousFile, $year);
            $csvFiles[] = $mousFile;

            // 5. Ekspor Manifest JSON
            $manifestFile = 'manifest.json';
            $manifestData = [
                'public_uuid'     => $publicUuid,
                'archive_year'    => $year,
                'generated_at'    => now()->toIso8601String(),
                'generated_by'    => $actor ? [
                    'name'  => $actor->name,
                    'email' => $actor->email,
                    'role'  => $actor->role,
                ] : null,
                'record_counts'   => $counts,
                'files'           => $csvFiles,
                'system_version'  => 'SevenInc-IMS-V7',
            ];
            file_put_contents(
                $tempDir . DIRECTORY_SEPARATOR . $manifestFile,
                json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );

            // 6. Bundling ke file ZIP
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException("Ekstensi PHP 'zip' (ZipArchive) belum aktif di server ini. Mohon aktifkan ekstensi 'zip' pada php.ini.");
            }

            $tempZipPath = $tempDir . DIRECTORY_SEPARATOR . $zipFileName;
            $zip = new ZipArchive();
            $zipStatus = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($zipStatus !== true) {
                throw new RuntimeException("Gagal menginisialisasi berkas ZIP (kode error: {$zipStatus}).");
            }

            foreach ($csvFiles as $file) {
                $zip->addFile($tempDir . DIRECTORY_SEPARATOR . $file, $file);
            }
            $zip->addFile($tempDir . DIRECTORY_SEPARATOR . $manifestFile, $manifestFile);
            $zip->close();

            // Pindahkan berkas ZIP ke storage Laravel
            $zipContent = file_get_contents($tempZipPath);
            Storage::disk('local')->put($relativeStoragePath, $zipContent);
            $fileSizeBytes = strlen($zipContent);

            // Simpan record arsip ke database
            $archiveExport = ArchiveExport::create([
                'public_uuid'     => $publicUuid,
                'archive_year'    => $year,
                'file_name'       => $zipFileName,
                'file_path'       => $relativeStoragePath,
                'file_size_bytes' => $fileSizeBytes,
                'file_manifest'   => $manifestData,
                'status'          => ArchiveExport::STATUS_COMPLETED,
                'generated_by'    => $actor?->id,
                'generated_at'    => now(),
            ]);

            // Catat log aktivitas audit
            ActivityLogger::log(
                action: 'archive_exported',
                description: "Paket arsip tahun {$year} berhasil digenerate ({$archiveExport->formatted_file_size})",
                subject: $archiveExport,
                actor: $actor,
                metadata: [
                    'year'        => $year,
                    'file_name'   => $zipFileName,
                    'file_size'   => $fileSizeBytes,
                    'counts'      => $counts,
                    'public_uuid' => $publicUuid,
                ]
            );

            return $archiveExport;
        } catch (Exception $e) {
            // Catat log error jika terjadi kegagalan
            ArchiveExport::create([
                'public_uuid'     => $publicUuid,
                'archive_year'    => $year,
                'file_name'       => $zipFileName,
                'file_path'       => $relativeStoragePath,
                'status'          => ArchiveExport::STATUS_FAILED,
                'failure_message' => $e->getMessage(),
                'generated_by'    => $actor?->id,
                'generated_at'    => now(),
            ]);

            throw $e;
        } finally {
            // Bersihkan file dan folder sementara
            $this->cleanDirectory($tempDir);
        }
    }

    /**
     * Hapus arsip dan file fisiknya dari storage.
     */
    public function deleteArchive(ArchiveExport $archive, ?User $actor = null): bool
    {
        if (Storage::disk('local')->exists($archive->file_path)) {
            Storage::disk('local')->delete($archive->file_path);
        }

        ActivityLogger::log(
            action: 'archive_deleted',
            description: "Paket arsip tahun {$archive->archive_year} ({$archive->file_name}) telah dihapus",
            subject: $archive,
            actor: $actor,
            metadata: [
                'archive_year' => $archive->archive_year,
                'file_name'    => $archive->file_name,
                'public_uuid'  => $archive->public_uuid,
            ]
        );

        return (bool) $archive->delete();
    }

    /**
     * Tulis berkas CSV pemagang dengan UTF-8 BOM.
     */
    protected function writeInternsCsv(string $filePath, int $year): void
    {
        $handle = fopen($filePath, 'w');
        // UTF-8 BOM untuk kompatibilitas Excel
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'No',
            'Nama Lengkap',
            'Nomor Induk Mahasiswa / Nomor Induk Siswa Nasional',
            'Jenis Kelamin',
            'Nomor Telepon / WA',
            'Asal Institusi / Sekolah',
            'Program Studi / Jurusan',
            'Divisi Magang',
            'Tanggal Mulai',
            'Tanggal Selesai',
            'Status Magang',
            'Tanggal Pendaftaran',
        ]);

        $query = InternshipRegistration::query()
            ->with(['institution', 'studyProgramRel', 'division'])
            ->where(function ($q) use ($year) {
                $q->whereYear('start_date', $year)
                  ->orWhereYear('created_at', $year);
            })
            ->orderBy('id', 'asc');

        $number = 1;
        $query->chunk(250, function ($registrations) use ($handle, &$number) {
            foreach ($registrations as $reg) {
                fputcsv($handle, [
                    $number++,
                    $reg->fullname ?? '-',
                    $reg->student_id ?? '-',
                    $reg->gender ?? '-',
                    $reg->phone_number ?? '-',
                    $reg->institution?->name ?? ($reg->institution_name ?? '-'),
                    $reg->studyProgramRel?->name ?? ($reg->study_program ?? '-'),
                    $reg->division?->name ?? '-',
                    $reg->start_date ? Carbon::parse($reg->start_date)->format('d/m/Y') : '-',
                    $reg->end_date ? Carbon::parse($reg->end_date)->format('d/m/Y') : '-',
                    ucfirst($reg->internship_status ?? 'waiting'),
                    $reg->created_at ? $reg->created_at->format('d/m/Y H:i') : '-',
                ]);
            }
        });

        fclose($handle);
    }

    /**
     * Tulis berkas CSV kontak guru/dosen dengan UTF-8 BOM.
     */
    protected function writeContactsCsv(string $filePath, int $year): void
    {
        $handle = fopen($filePath, 'w');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'No',
            'Kode Kontak (UUID)',
            'Nama Guru / Dosen Pembimbing',
            'Jabatan / Posisi',
            'Institusi Asal',
            'Nomor Telepon / WA',
            'Email',
            'Status Aktif',
            'Catatan Khusus',
            'Tanggal Terdaftar',
        ]);

        $query = InstitutionContact::query()
            ->with(['institution'])
            ->whereYear('created_at', '<=', $year)
            ->where(function ($q) use ($year) {
                $q->whereYear('created_at', $year)
                  ->orWhereHas('followUpCycles', fn ($f) => $f->where('cycle_year', $year));
            })
            ->orderBy('id', 'asc');

        $number = 1;
        $query->chunk(250, function ($contacts) use ($handle, &$number) {
            foreach ($contacts as $contact) {
                fputcsv($handle, [
                    $number++,
                    $contact->public_uuid,
                    $contact->name ?? '-',
                    $contact->position_title ?? '-',
                    $contact->institution?->name ?? '-',
                    $contact->phone ?? '-',
                    $contact->email ?? '-',
                    $contact->is_active ? 'Aktif' : 'Non-aktif',
                    $contact->notes ?? '-',
                    $contact->created_at ? $contact->created_at->format('d/m/Y H:i') : '-',
                ]);
            }
        });

        fclose($handle);
    }

    /**
     * Tulis berkas CSV siklus follow-up dengan UTF-8 BOM.
     */
    protected function writeFollowUpsCsv(string $filePath, int $year): void
    {
        $handle = fopen($filePath, 'w');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'No',
            'Kode Siklus (UUID)',
            'Nama Institusi',
            'Kontak Dituju',
            'Tahun Siklus',
            'Status Siklus',
            'Hasil Akhir',
            'Jumlah Percobaan Dilakukan',
            'Waktu Mulai',
            'Waktu Selesai',
            'Catatan Hasil Akhir',
        ]);

        $query = FollowUpCycle::query()
            ->with(['institution', 'contact'])
            ->where('cycle_year', $year)
            ->orderBy('id', 'asc');

        $number = 1;
        $query->chunk(250, function ($cycles) use ($handle, &$number) {
            foreach ($cycles as $cycle) {
                fputcsv($handle, [
                    $number++,
                    $cycle->public_uuid,
                    $cycle->institution?->name ?? '-',
                    $cycle->contact?->name ?? 'Belum Ditentukan',
                    $cycle->cycle_year,
                    $cycle->status_label ?? $cycle->status,
                    $cycle->final_result_label ?? ($cycle->final_result ?? 'Belum ada hasil'),
                    $cycle->current_attempt_count ?? 0,
                    $cycle->started_at ? $cycle->started_at->format('d/m/Y H:i') : '-',
                    $cycle->completed_at ? $cycle->completed_at->format('d/m/Y H:i') : '-',
                    $cycle->final_notes ?? '-',
                ]);
            }
        });

        fclose($handle);
    }

    /**
     * Tulis berkas CSV naskah dan kemitraan MOU dengan UTF-8 BOM.
     */
    protected function writeMousCsv(string $filePath, int $year): void
    {
        $handle = fopen($filePath, 'w');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'No',
            'Kode MOU (UUID)',
            'Nomor MOU Perusahaan',
            'Nomor MOU Institusi',
            'Nama Institusi',
            'Nama Penandatangan Pihak 1',
            'Jabatan Penandatangan Pihak 1',
            'Tanggal Perjanjian Disepakati',
            'Mulai Berlaku',
            'Selesai Berlaku',
            'Status MOU',
            'Metode Pengiriman',
            'Waktu Dibuat',
        ]);

        $query = Mou::query()
            ->with(['institution'])
            ->where(function ($q) use ($year) {
                $q->whereYear('agreement_date', $year)
                  ->orWhereYear('effective_start', $year)
                  ->orWhereYear('created_at', $year);
            })
            ->orderBy('id', 'asc');

        $number = 1;
        $query->chunk(250, function ($mous) use ($handle, &$number) {
            foreach ($mous as $mou) {
                fputcsv($handle, [
                    $number++,
                    $mou->public_uuid,
                    $mou->company_mou_number ?? '-',
                    $mou->institution_mou_number ?? '-',
                    $mou->institution?->name ?? ($mou->first_party_institution_name ?? '-'),
                    $mou->first_party_signatory_name ?? '-',
                    $mou->first_party_signatory_title ?? '-',
                    $mou->agreement_date ? Carbon::parse($mou->agreement_date)->format('d/m/Y') : '-',
                    $mou->effective_start ? Carbon::parse($mou->effective_start)->format('d/m/Y') : '-',
                    $mou->effective_end ? Carbon::parse($mou->effective_end)->format('d/m/Y') : '-',
                    $mou->status_label ?? $mou->status,
                    $mou->delivery_method ?? '-',
                    $mou->created_at ? $mou->created_at->format('d/m/Y H:i') : '-',
                ]);
            }
        });

        fclose($handle);
    }

    /**
     * Bersihkan folder dan berkas sementara.
     */
    protected function cleanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->cleanDirectory($itemPath);
            } else {
                @unlink($itemPath);
            }
        }
        @rmdir($dir);
    }
}
