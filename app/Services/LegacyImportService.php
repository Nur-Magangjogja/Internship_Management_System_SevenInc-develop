<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\User;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LegacyImportService
{
    /**
     * Parse berkas CSV menjadi array data terstruktur.
     */
    public function parseCsv(UploadedFile|string $file): array
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Berkas CSV tidak ditemukan: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Gagal membaca berkas CSV.");
        }

        // Tangani UTF-8 BOM jika ada
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = [];
        $rows = [];
        $rowNumber = 0;

        while (($data = fgetcsv($handle, 2000, ',')) !== false) {
            $rowNumber++;

            // Jika baris kosong seluruhnya, lewati
            if (empty(array_filter($data, fn ($val) => trim($val) !== ''))) {
                continue;
            }

            if (empty($headers)) {
                // Header baris pertama
                $headers = array_map(function ($h) {
                    $clean = trim($h);
                    $clean = strtolower($clean);
                    $clean = str_replace([' ', '/', '-', '.'], '_', $clean);
                    return preg_replace('/[^a-z0-9_]/', '', $clean);
                }, $data);
                continue;
            }

            // Pad row data jika panjangnya berbeda dengan header
            $paddedData = array_pad($data, count($headers), '');
            $combined = [];
            foreach ($headers as $index => $headerKey) {
                if (!empty($headerKey)) {
                    $combined[$headerKey] = trim($paddedData[$index] ?? '');
                }
            }

            $combined['_row_number'] = $rowNumber;
            $rows[] = $combined;
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'rows'    => $rows,
            'total'   => count($rows),
        ];
    }

    /**
     * Preview dan analisis data staging sebelum di-commit ke database.
     */
    public function previewImport(array $rows): array
    {
        $analyzedRows = [];
        $stats = [
            'total_rows'             => count($rows),
            'new_institutions'       => 0,
            'existing_institutions'  => 0,
            'ambiguous_institutions' => 0,
            'new_contacts'           => 0,
            'existing_contacts'      => 0,
            'unverified_candidates'  => 0,
            'has_followup'           => 0,
            'invalid_rows'           => 0,
        ];

        // Cache institusi dan kontak yang sudah ada di DB untuk kecepatan pencarian
        $existingInstitutions = Institution::all()->keyBy(fn ($i) => Institution::normalizeName($i->name));

        foreach ($rows as $row) {
            $rowErrors = [];

            // 1. Ekstraksi Data Institusi
            $institutionName = $this->extractField($row, ['nama_institusi', 'institusi', 'nama_sekolah', 'sekolah', 'kampus', 'universitas']);
            if (empty($institutionName)) {
                $rowErrors[] = 'Nama institusi tidak boleh kosong.';
            }

            $normalizedInstName = !empty($institutionName) ? Institution::normalizeName($institutionName) : '';
            $matchedInstitution = $existingInstitutions->get($normalizedInstName);

            $isAmbiguous = false;
            $ambiguousCandidate = null;
            if (!$matchedInstitution && !empty($normalizedInstName)) {
                $candidate = $existingInstitutions->first(function ($inst) use ($normalizedInstName) {
                    $instNorm = Institution::normalizeName($inst->name);
                    return (strlen($instNorm) > 4 && strlen($normalizedInstName) > 4) &&
                           (str_contains($instNorm, $normalizedInstName) || str_contains($normalizedInstName, $instNorm));
                });
                if ($candidate) {
                    $isAmbiguous = true;
                    $ambiguousCandidate = $candidate->name;
                    $stats['ambiguous_institutions']++;
                }
            }

            $institutionAction = $matchedInstitution ? 'link_existing' : 'insert_new';
            if ($matchedInstitution) {
                $stats['existing_institutions']++;
            } elseif (!empty($institutionName)) {
                $stats['new_institutions']++;
            }

            // 2. Ekstraksi Data Kontak Pembimbing
            $contactName = $this->extractField($row, ['nama_guru_dosen', 'nama_guru', 'nama_dosen', 'nama_pembimbing', 'nama_kontak', 'kontak_nama', 'guru_dosen', 'pembimbing']);
            $contactPhone = $this->extractField($row, ['nomor_telepon_wa', 'no_telepon_wa', 'nomor_telepon', 'no_telepon', 'nomor_wa', 'no_wa', 'telepon', 'kontak_telepon', 'telepon_kontak']);
            $contactEmail = $this->extractField($row, ['email_kontak', 'email_guru', 'email_dosen', 'kontak_email', 'email']);
            $contactPosition = $this->extractField($row, ['jabatan', 'posisi', 'jabatan_kontak', 'posisi_kontak', 'jabatan_guru', 'jabatan_dosen']);

            $hasContact = !empty($contactName) || !empty($contactPhone);
            $contactAction = 'none';

            if ($hasContact && $matchedInstitution) {
                // Cek apakah kontak sudah ada di institusi yang cocok
                $normPhone = !empty($contactPhone) ? InstitutionContact::normalizePhone($contactPhone) : null;
                $contactExists = $matchedInstitution->contacts()
                    ->where(function ($q) use ($contactName, $normPhone) {
                        if ($normPhone) {
                            $q->where('normalized_phone', $normPhone);
                        }
                        if ($contactName) {
                            $q->orWhere('name', 'like', $contactName);
                        }
                    })
                    ->exists();

                $contactAction = $contactExists ? 'link_existing' : 'insert_new';
                if ($contactExists) {
                    $stats['existing_contacts']++;
                } else {
                    $stats['new_contacts']++;
                    $stats['unverified_candidates']++;
                }
            } elseif ($hasContact) {
                $contactAction = 'insert_new';
                $stats['new_contacts']++;
                $stats['unverified_candidates']++;
            }

            // 3. Ekstraksi Follow-Up (opsional jika baris memiliki riwayat follow-up)
            $followUpYear = $this->extractField($row, ['tahun_siklus', 'tahun_follow_up', 'tahun', 'cycle_year']);
            $followUpStatus = $this->extractField($row, ['status_follow_up', 'hasil_follow_up', 'status_siklus']);
            if (!empty($followUpYear) || !empty($followUpStatus)) {
                $stats['has_followup']++;
            }

            if (!empty($rowErrors)) {
                $stats['invalid_rows']++;
            }

            $analyzedRows[] = [
                'row_number'          => $row['_row_number'] ?? 0,
                'institution_name'    => $institutionName,
                'institution_matched' => $matchedInstitution?->name,
                'institution_action'  => $institutionAction,
                'is_ambiguous'        => $isAmbiguous,
                'ambiguous_candidate' => $ambiguousCandidate,
                'contact_name'        => $contactName,
                'contact_phone'       => $contactPhone,
                'contact_email'       => $contactEmail,
                'contact_position'    => $contactPosition,
                'contact_action'      => $contactAction,
                'is_candidate'        => ($contactAction === 'insert_new'),
                'followup_year'       => $followUpYear ?: date('Y'),
                'followup_status'     => $followUpStatus,
                'errors'              => $rowErrors,
                'raw'                 => $row,
            ];
        }

        return [
            'stats' => $stats,
            'rows'  => $analyzedRows,
        ];
    }

    /**
     * Commit dan simpan data staging ke dalam database secara atomik.
     */
    public function commitImport(array $rows, ?User $actor = null): array
    {
        return DB::transaction(function () use ($rows, $actor) {
            $insertedInstitutionsCount = 0;
            $linkedInstitutionsCount   = 0;
            $insertedContactsCount       = 0;
            $linkedContactsCount         = 0;
            $insertedCyclesCount         = 0;
            $skippedRowsCount            = 0;

            foreach ($rows as $row) {
                $institutionName = $this->extractField($row, ['nama_institusi', 'institusi', 'nama_sekolah', 'sekolah', 'kampus', 'universitas']);

                if (empty($institutionName)) {
                    $skippedRowsCount++;
                    continue;
                }

                // 1. Dapatkan atau Buat Institusi
                $normalizedName = Institution::normalizeName($institutionName);
                $institution = Institution::where('normalized_name', $normalizedName)
                    ->orWhereRaw("LOWER(REPLACE(name, '  ', ' ')) = ?", [$normalizedName])
                    ->first();

                if (!$institution) {
                    $address = $this->extractField($row, ['alamat', 'alamat_institusi', 'alamat_sekolah']);
                    $phone   = $this->extractField($row, ['telepon_institusi', 'telepon_kantor', 'no_kantor']);
                    $email   = $this->extractField($row, ['email_institusi', 'email_sekolah', 'email_resmi']);
                    $unit    = $this->extractField($row, ['unit_korespondensi', 'divisi_humas', 'unit']);

                    $institution = Institution::create([
                        'public_uuid'         => (string) Str::uuid(),
                        'name'                => $institutionName,
                        'address'             => $address ?: null,
                        'phone'               => $phone ?: null,
                        'email'               => $email ?: null,
                        'correspondence_unit' => $unit ?: 'Hubungan Industri / BKK',
                        'is_active'           => true,
                    ]);

                    $insertedInstitutionsCount++;
                } else {
                    $linkedInstitutionsCount++;
                }

                // 2. Dapatkan atau Buat Kontak Pembimbing
                $contactName = $this->extractField($row, ['nama_guru_dosen', 'nama_guru', 'nama_dosen', 'nama_pembimbing', 'nama_kontak', 'kontak_nama', 'guru_dosen', 'pembimbing']);
                $contactPhone = $this->extractField($row, ['nomor_telepon_wa', 'no_telepon_wa', 'nomor_telepon', 'no_telepon', 'nomor_wa', 'no_wa', 'telepon', 'kontak_telepon', 'telepon_kontak']);
                $contactEmail = $this->extractField($row, ['email_kontak', 'email_guru', 'email_dosen', 'kontak_email', 'email']);
                $contactPosition = $this->extractField($row, ['jabatan', 'posisi', 'jabatan_kontak', 'posisi_kontak', 'jabatan_guru', 'jabatan_dosen']);

                $contact = null;
                if (!empty($contactName) || !empty($contactPhone)) {
                    $normalizedPhone = !empty($contactPhone) ? InstitutionContact::normalizePhone($contactPhone) : null;

                    $contact = $institution->contacts()
                        ->where(function ($q) use ($contactName, $normalizedPhone) {
                            if ($normalizedPhone) {
                                $q->where('normalized_phone', $normalizedPhone);
                            }
                            if ($contactName) {
                                $normalizedContactName = InstitutionContact::normalizeName($contactName);
                                $q->orWhere('normalized_name', $normalizedContactName)
                                  ->orWhere('name', $contactName);
                            }
                        })
                        ->first();

                    if (!$contact) {
                        $contact = InstitutionContact::create([
                            'public_uuid'      => (string) Str::uuid(),
                            'institution_id'   => $institution->id,
                            'name'             => $contactName ?: 'Guru / Dosen Pembimbing',
                            'position_title'   => $contactPosition ?: 'Pembimbing Lapangan',
                            'phone'            => $contactPhone ?: null,
                            'normalized_phone' => $normalizedPhone,
                            'email'            => $contactEmail ?: null,
                            'is_active'        => true,
                            'is_verified'      => false,
                            'notes'            => 'Diimpor dari data legacy spreadsheet (kandidat review HR)',
                        ]);
                        $insertedContactsCount++;
                    } else {
                        $linkedContactsCount++;
                    }
                }

                // 3. Buat Siklus Follow-Up jika ada penanda riwayat dan kontak terdaftar
                $followUpYear = (int) $this->extractField($row, ['tahun_siklus', 'tahun_follow_up', 'tahun', 'cycle_year']);
                $followUpStatus = $this->extractField($row, ['status_follow_up', 'hasil_follow_up', 'status_siklus']);

                if ($followUpYear > 2000 && $contact) {
                    $cycle = FollowUpCycle::firstOrCreate(
                        [
                            'institution_id'         => $institution->id,
                            'institution_contact_id' => $contact->id,
                            'cycle_year'             => $followUpYear,
                        ],
                        [
                            'public_uuid'            => (string) Str::uuid(),
                            'status'                 => $followUpStatus === 'bersedia' ? FollowUpCycle::STATUS_COMPLETED : FollowUpCycle::STATUS_OPEN,
                            'final_result'           => $followUpStatus === 'bersedia' ? FollowUpCycle::RESULT_WILLING : null,
                            'started_at'             => now(),
                            'completed_at'           => $followUpStatus === 'bersedia' ? now() : null,
                        ]
                    );

                    if ($cycle->wasRecentlyCreated) {
                        $insertedCyclesCount++;
                    }
                }
            }

            $summary = [
                'inserted_institutions' => $insertedInstitutionsCount,
                'linked_institutions'   => $linkedInstitutionsCount,
                'inserted_contacts'     => $insertedContactsCount,
                'linked_contacts'       => $linkedContactsCount,
                'inserted_cycles'       => $insertedCyclesCount,
                'skipped_rows'          => $skippedRowsCount,
                'total_processed'       => count($rows),
            ];

            // Catat log aktivitas
            ActivityLogger::log(
                action: 'legacy_data_imported',
                description: "Impor data legacy selesai: {$insertedInstitutionsCount} institusi baru, {$insertedContactsCount} kontak baru, {$insertedCyclesCount} siklus",
                subject: $actor,
                actor: $actor,
                metadata: $summary
            );

            return $summary;
        });
    }

    /**
     * Helper untuk mengambil nilai field dengan toleransi nama alias header.
     */
    protected function extractField(array $row, array $possibleKeys): ?string
    {
        foreach ($possibleKeys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return null;
    }
}
