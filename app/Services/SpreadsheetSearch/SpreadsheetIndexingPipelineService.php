<?php

namespace App\Services\SpreadsheetSearch;

use App\Jobs\IndexSpreadsheetSheetJob;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSearchDocument;
use App\Models\SpreadsheetSearchIndexRun;
use App\Models\SpreadsheetTabProfile;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SpreadsheetIndexingPipelineService
{
    public function __construct(
        protected SpreadsheetTransportInterface $transport
    ) {}

    /**
     * Memulai proses index run baru dengan safe generation increment.
     */
    public function startIndexRun(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $sheetId = null,
        int $pageSize = 200,
        bool $dispatchQueue = true
    ): SpreadsheetSearchIndexRun {
        // Tentukan generasi indeks berikutnya untuk safe atomic rebuild
        $currentMaxGen = (int) SpreadsheetSearchDocument::where('spreadsheet_connection_id', $connection->id)
            ->where('tab_name', $tabName)
            ->max('index_generation') ?? 0;

        $nextGen = $currentMaxGen + 1;

        $run = SpreadsheetSearchIndexRun::create([
            'spreadsheet_connection_id' => $connection->id,
            'sheet_id'                  => $sheetId,
            'tab_name'                  => $tabName,
            'index_generation'          => $nextGen,
            'status'                    => SpreadsheetSearchIndexRun::STATUS_PROCESSING,
            'page_size'                 => min(200, max(50, $pageSize)),
            'current_page'              => 1,
            'started_at'                => now(),
        ]);

        if ($dispatchQueue) {
            IndexSpreadsheetSheetJob::dispatch($run->id, 1);
        }

        return $run;
    }

    /**
     * Memproses satu batch halaman data dan mengindeksnya ke spreadsheet_search_documents.
     */
    public function processPageBatch(SpreadsheetSearchIndexRun $run, int $page): array
    {
        $connection = $run->connection;
        if (!$connection) {
            throw new \RuntimeException("SpreadsheetConnection untuk run #{$run->id} tidak ditemukan.");
        }

        // Ambil profil tab untuk header_row dan data_start_row
        $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $connection->id)
            ->where('tab_name', $run->tab_name)
            ->first();

        $headerRow = $profile?->header_row ?? 10;
        $dataStartRow = $profile?->data_start_row ?? ($headerRow + 1);

        // Ambil halaman baris tanpa warna sel (fetchColors = false) dan unmasked untuk indexing akurat
        $response = $this->transport->readRowsPage(
            connection: $connection,
            tabName: $run->tab_name,
            headerRow: $headerRow,
            page: $page,
            pageSize: $run->page_size,
            maskPii: false,
            fetchColors: false,
            dataStartRow: $dataStartRow
        );

        if (!$response->success) {
            throw new \RuntimeException("Gagal membaca baris dari spreadsheet: " . ($response->errorMessage ?: 'Unknown error'));
        }

        $context = $response->context ?? [];
        $rows = $context['rows'] ?? [];
        $totalRowsReported = $context['total_rows'] ?? count($rows);
        $hasMore = (bool) ($context['has_more'] ?? false);

        if ($run->total_rows === 0 && $totalRowsReported > 0) {
            $run->total_rows = $totalRowsReported;
        }

        $indexedCount = 0;
        $skippedCount = 0;

        if (!empty($rows)) {
            // Batch lookup kandidat registrasi lokal berdasarkan brand
            $emailsToLookup = [];
            $phonesToLookup = [];
            $syncIdsToLookup = [];

            foreach ($rows as $row) {
                $data = $row['data'] ?? [];
                $email = $this->extractEmail($data);
                if ($email) $emailsToLookup[] = $email;

                $phone = $this->extractPhone($data);
                if ($phone) $phonesToLookup[] = $phone;

                $syncId = $this->extractSyncId($data, $row);
                if ($syncId) $syncIdsToLookup[] = $syncId;
            }

            $localRegsByEmail = !empty($emailsToLookup)
                ? InternshipRegistration::where('brand_id', $connection->brand_id)
                    ->whereIn('email', array_unique($emailsToLookup))
                    ->pluck('id', 'email')
                    ->all()
                : [];

            $localRegsByPhone = !empty($phonesToLookup)
                ? InternshipRegistration::where('brand_id', $connection->brand_id)
                    ->whereIn('phone_number', array_unique($phonesToLookup))
                    ->pluck('id', 'phone_number')
                    ->all()
                : [];

            $existingRowSyncs = !empty($syncIdsToLookup)
                ? SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
                    ->whereIn('external_sync_id', array_unique($syncIdsToLookup))
                    ->pluck('id', 'external_sync_id')
                    ->all()
                : [];

            $documentsToInsert = [];
            $now = now();

            foreach ($rows as $row) {
                $data = $row['data'] ?? [];
                $rowNumber = $row['row_number'] ?? null;

                // Abaikan baris kosong
                $nonEmptyValues = array_filter(array_map('trim', array_map('strval', array_values($data))));
                if (empty($nonEmptyValues)) {
                    $skippedCount++;
                    continue;
                }

                $name = $this->extractName($data);
                $email = $this->extractEmail($data);
                $phone = $this->extractPhone($data);
                $syncId = $this->extractSyncId($data, $row);
                $regId = $this->extractRegId($data);

                // Identifikasi tipe dan local_registration_id
                $localRegId = null;
                $identityType = SpreadsheetSearchDocument::IDENTITY_PROVISIONAL;

                if (!empty($syncId)) {
                    // Baris yang sudah memiliki SERTIF_SYNC_ID / external_sync_id adalah stable
                    $identityType = SpreadsheetSearchDocument::IDENTITY_STABLE;
                } elseif ($regId && InternshipRegistration::where('id', (int) $regId)->where('brand_id', $connection->brand_id)->exists()) {
                    $localRegId = (int) $regId;
                    $identityType = SpreadsheetSearchDocument::IDENTITY_STABLE;
                } elseif ($email && isset($localRegsByEmail[$email])) {
                    $localRegId = $localRegsByEmail[$email];
                    $identityType = SpreadsheetSearchDocument::IDENTITY_STABLE;
                } elseif ($phone && isset($localRegsByPhone[$phone])) {
                    $localRegId = $localRegsByPhone[$phone];
                    $identityType = SpreadsheetSearchDocument::IDENTITY_STABLE;
                }

                // Check conflict: jika email dan phone cocok pada reg berbeda
                if ($email && $phone && isset($localRegsByEmail[$email]) && isset($localRegsByPhone[$phone])) {
                    if ($localRegsByEmail[$email] !== $localRegsByPhone[$phone]) {
                        $identityType = SpreadsheetSearchDocument::IDENTITY_AMBIGUOUS;
                    }
                }

                // Normalisasi string
                $normName = $name ? mb_strtolower(preg_replace('/\s+/', ' ', trim($name))) : null;
                $normEmail = $email ? mb_strtolower(trim($email)) : null;
                $normPhone = $phone ? preg_replace('/\D/', '', $phone) : null;
                if ($normPhone && str_starts_with($normPhone, '0')) {
                    $normPhone = '62' . substr($normPhone, 1);
                }

                // Searchable text: gabungan seluruh nilai sel
                $searchableText = implode(' ', array_values($nonEmptyValues));
                if ($normName) $searchableText .= ' ' . $normName;
                if ($normEmail) $searchableText .= ' ' . $normEmail;
                if ($phone) $searchableText .= ' ' . $phone . ' ' . $normPhone;
                if ($syncId) $searchableText .= ' ' . $syncId;

                // Payload pratinjau ringkas
                $searchablePayload = [
                    'name'        => $name,
                    'email'       => $email,
                    'phone'       => $phone,
                    'institution' => $this->extractInstitution($data),
                    'major'       => $this->extractMajor($data),
                    'row_number'  => $rowNumber,
                    'sync_id'     => $syncId,
                    'status'      => $this->extractStatus($data),
                ];

                $fingerprint = hash('sha256', json_encode($data));

                $documentsToInsert[] = [
                    'public_uuid'               => (string) Str::uuid(),
                    'brand_id'                  => $connection->brand_id,
                    'spreadsheet_connection_id' => $connection->id,
                    'sheet_id'                  => $run->sheet_id,
                    'tab_name'                  => $run->tab_name,
                    'table_identifier'          => $run->table_identifier,
                    'row_sync_id'               => $syncId && isset($existingRowSyncs[$syncId]) ? $existingRowSyncs[$syncId] : null,
                    'local_registration_id'     => $localRegId,
                    'source_record_key'         => $syncId,
                    'source_row_number'         => $rowNumber,
                    'source_row_fingerprint'    => $fingerprint,
                    'identity_type'             => $identityType,
                    'mapping_version_id'        => $run->mapping_version_id,
                    'normalized_name'           => $normName,
                    'normalized_email'          => $normEmail,
                    'normalized_phone'          => $normPhone,
                    'raw_phone'                 => $phone,
                    'searchable_text'           => $searchableText,
                    'searchable_payload'        => json_encode($searchablePayload),
                    'indexed_at'                => $now,
                    'index_generation'          => $run->index_generation,
                    'created_at'                => $now,
                    'updated_at'                => $now,
                ];

                $indexedCount++;
            }

            if (!empty($documentsToInsert)) {
                // Chunk insert ke MySQL untuk efisiensi
                foreach (array_chunk($documentsToInsert, 100) as $chunk) {
                    DB::table('spreadsheet_search_documents')->insert($chunk);
                }
            }
        }

        $run->processed_rows += count($rows);
        $run->indexed_rows += $indexedCount;
        $run->skipped_rows += $skippedCount;
        $run->current_page = $page;

        // Cek kelanjutan
        if ($hasMore && count($rows) > 0) {
            $run->save();
            IndexSpreadsheetSheetJob::dispatch($run->id, $page + 1);
        } else {
            // Selesai seluruh baris!
            $run->status = SpreadsheetSearchIndexRun::STATUS_COMPLETED;
            $run->completed_at = now();
            $run->save();

            // Safe Atomic Promotion: Hapus generasi lama yang sudah tidak aktif
            SpreadsheetSearchDocument::where('spreadsheet_connection_id', $connection->id)
                ->where('tab_name', $run->tab_name)
                ->where('index_generation', '<', $run->index_generation)
                ->delete();

            Log::info("Indexing spreadsheet selesai untuk connection #{$connection->id} tab '{$run->tab_name}' gen #{$run->index_generation}. Total indexed: {$run->indexed_rows}");
        }

        return [
            'success'        => true,
            'page'           => $page,
            'rows_in_page'   => count($rows),
            'indexed'        => $indexedCount,
            'skipped'        => $skippedCount,
            'has_more'       => $hasMore,
            'is_completed'   => ($run->status === SpreadsheetSearchIndexRun::STATUS_COMPLETED),
        ];
    }

    /**
     * Proses penelusuran sinkron seluruh halaman (berguna untuk testing atau command line).
     */
    public function processSync(SpreadsheetSearchIndexRun $run, int $maxPages = 50): int
    {
        $currentPage = 1;
        while ($currentPage <= $maxPages) {
            $res = $this->processPageBatch($run, $currentPage);
            if ($res['is_completed'] || !$res['has_more']) {
                break;
            }
            $currentPage++;
        }

        return $run->refresh()->indexed_rows;
    }

    // =========================================================================
    // Helper Ekstraksi Field Logis
    // =========================================================================

    protected function extractName(array $data): ?string
    {
        foreach ($data as $k => $v) {
            $cleanK = mb_strtolower(trim((string) $k));
            if ((str_contains($cleanK, 'nama') || str_contains($cleanK, 'name')) && !str_contains($cleanK, 'sekolah') && !str_contains($cleanK, 'instansi') && !empty($v)) {
                return trim((string) $v);
            }
        }
        return null;
    }

    protected function extractEmail(array $data): ?string
    {
        foreach ($data as $k => $v) {
            $cleanK = mb_strtolower(trim((string) $k));
            if (str_contains($cleanK, 'email') || str_contains($cleanK, 'surel')) {
                $val = trim((string) $v);
                if (filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    return $val;
                }
            }
        }
        return null;
    }

    protected function extractPhone(array $data): ?string
    {
        foreach ($data as $k => $v) {
            $cleanK = mb_strtolower(trim((string) $k));
            if (str_contains($cleanK, 'hp') || str_contains($cleanK, 'telepon') || str_contains($cleanK, 'whatsapp') || str_contains($cleanK, 'wa') || str_contains($cleanK, 'phone')) {
                $val = trim((string) $v);
                if (!empty($val) && strlen(preg_replace('/\D/', '', $val)) >= 7) {
                    return $val;
                }
            }
        }
        return null;
    }

    protected function extractSyncId(array $data, array $row): ?string
    {
        if (!empty($row['sync_id'])) {
            return trim((string) $row['sync_id']);
        }
        foreach ($data as $k => $v) {
            $cleanK = mb_strtoupper(trim((string) $k));
            if ($cleanK === 'SERTIF_SYNC_ID' || $cleanK === 'SYNC_ID' || $cleanK === 'EXTERNAL_SYNC_ID') {
                $val = trim((string) $v);
                if (!empty($val)) return $val;
            }
        }
        return null;
    }

    protected function extractRegId(array $data): ?string
    {
        foreach ($data as $k => $v) {
            $cleanK = mb_strtoupper(trim((string) $k));
            if ($cleanK === 'SERTIF_REGISTRATION_ID' || $cleanK === 'REGISTRATION_ID') {
                $val = trim((string) $v);
                if (!empty($val)) return $val;
            }
        }
        return null;
    }

    protected function extractInstitution(array $data): ?string
    {
        foreach ($data as $k => $v) {
            $cleanK = mb_strtolower(trim((string) $k));
            if (str_contains($cleanK, 'sekolah') || str_contains($cleanK, 'universitas') || str_contains($cleanK, 'kampus') || str_contains($cleanK, 'instansi')) {
                $val = trim((string) $v);
                if (!empty($val)) return $val;
            }
        }
        return null;
    }

    protected function extractMajor(array $data): ?string
    {
        foreach ($data as $k => $v) {
            $cleanK = mb_strtolower(trim((string) $k));
            if (str_contains($cleanK, 'jurusan') || str_contains($cleanK, 'prodi') || str_contains($cleanK, 'program studi')) {
                $val = trim((string) $v);
                if (!empty($val)) return $val;
            }
        }
        return null;
    }

    protected function extractStatus(array $data): ?string
    {
        foreach ($data as $k => $v) {
            $cleanK = mb_strtolower(trim((string) $k));
            if ($cleanK === 'status' || str_contains($cleanK, 'status pemagang') || str_contains($cleanK, 'status pendaftaran')) {
                $val = trim((string) $v);
                if (!empty($val)) return $val;
            }
        }
        return null;
    }
}
