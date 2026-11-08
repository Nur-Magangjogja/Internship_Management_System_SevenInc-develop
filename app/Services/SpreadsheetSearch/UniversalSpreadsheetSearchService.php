<?php

namespace App\Services\SpreadsheetSearch;

use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSearchDocument;
use App\Models\SpreadsheetSearchIndexRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UniversalSpreadsheetSearchService
{
    /**
     * Cari dokumen pendaftaran pada proyeksi lokal database.
     */
    public function search(
        SpreadsheetConnection $connection,
        string $searchQuery,
        ?string $tabName = null,
        ?string $identityType = null,
        int $page = 1,
        int $pageSize = 20
    ): array {
        $startTime = microtime(true);
        $rawQuery = trim($searchQuery);

        $clampedPage = max(1, $page);
        $clampedPageSize = min(50, max(1, $pageSize));

        // Info kesegaran indeks
        $lastRun = SpreadsheetSearchIndexRun::where('spreadsheet_connection_id', $connection->id)
            ->where('status', SpreadsheetSearchIndexRun::STATUS_COMPLETED)
            ->latest('completed_at')
            ->first();

        $lastIndexedAt = $lastRun?->completed_at?->toIso8601String();
        $isStale = $lastRun ? ($lastRun->completed_at->diffInDays(now()) >= 3) : true;

        if ($rawQuery === '') {
            // Jika query kosong, tampilkan dokumen terbaru dari generasi aktif
            $baseQuery = $this->buildBaseScopeQuery($connection, $tabName, $identityType);
            $total = $baseQuery->count();
            $documents = $baseQuery->orderBy('source_row_number', 'asc')
                ->skip(($clampedPage - 1) * $clampedPageSize)
                ->take($clampedPageSize)
                ->get();

            $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success'         => true,
                'query'           => '',
                'total'           => $total,
                'page'            => $clampedPage,
                'page_size'       => $clampedPageSize,
                'elapsed_ms'      => $elapsedMs,
                'index_freshness' => [
                    'last_indexed_at' => $lastIndexedAt,
                    'is_stale'        => $isStale,
                ],
                'results'         => $documents->map(fn($d) => $this->formatResultItem($d, 'recent', 1.0, ''))->all(),
            ];
        }

        // Normalisasi query
        $normQuery = mb_strtolower(preg_replace('/\s+/', ' ', $rawQuery));
        $phoneDigits = preg_replace('/\D/', '', $rawQuery);
        $normPhone = $phoneDigits;
        if (str_starts_with($phoneDigits, '0')) {
            $normPhone = '62' . substr($phoneDigits, 1);
        }

        $results = collect();

        // ---------------------------------------------------------------------
        // Langkah 1: Exact Match (SERTIF_SYNC_ID, Email, No. HP, Nama Lengkap)
        // ---------------------------------------------------------------------
        $exactQuery = $this->buildBaseScopeQuery($connection, $tabName, $identityType)
            ->where(function ($q) use ($rawQuery, $normQuery, $phoneDigits, $normPhone) {
                $q->where('source_record_key', $rawQuery)
                  ->orWhere('normalized_email', $normQuery)
                  ->orWhere('normalized_name', $normQuery);

                if (!empty($phoneDigits) && strlen($phoneDigits) >= 5) {
                    $q->orWhere('normalized_phone', $normPhone)
                      ->orWhere('raw_phone', 'like', "%{$phoneDigits}%");
                }
            });

        $exactMatches = $exactQuery->take(50)->get();
        foreach ($exactMatches as $doc) {
            $results->put($doc->id, [
                'doc'             => $doc,
                'match_type'      => 'exact_match',
                'relevance_score' => 1.0,
            ]);
        }

        // ---------------------------------------------------------------------
        // Langkah 2: Prefix Match (Nama LIKE 'query%' atau Email LIKE 'query%')
        // ---------------------------------------------------------------------
        if ($results->count() < $clampedPageSize && mb_strlen($normQuery) >= 2) {
            $prefixQuery = $this->buildBaseScopeQuery($connection, $tabName, $identityType)
                ->where(function ($q) use ($normQuery) {
                    $q->where('normalized_name', 'like', "{$normQuery}%")
                      ->orWhere('normalized_email', 'like', "{$normQuery}%");
                });

            if ($results->isNotEmpty()) {
                $prefixQuery->whereNotIn('id', $results->keys()->all());
            }

            $prefixMatches = $prefixQuery->take($clampedPageSize - $results->count())->get();
            foreach ($prefixMatches as $doc) {
                $results->put($doc->id, [
                    'doc'             => $doc,
                    'match_type'      => 'prefix_match',
                    'relevance_score' => 0.85,
                ]);
            }
        }

        // ---------------------------------------------------------------------
        // Langkah 3: FULLTEXT Match (MySQL) atau Substring Token Match (SQLite)
        // ---------------------------------------------------------------------
        if ($results->count() < $clampedPageSize && mb_strlen($normQuery) >= 3) {
            $fulltextQuery = $this->buildBaseScopeQuery($connection, $tabName, $identityType);

            if ($results->isNotEmpty()) {
                $fulltextQuery->whereNotIn('id', $results->keys()->all());
            }

            $isMysql = (DB::getDriverName() === 'mysql');
            $words = array_filter(explode(' ', $normQuery));

            if ($isMysql && !empty($words)) {
                $booleanTerms = implode(' ', array_map(fn($w) => '+' . preg_replace('/[+\-><()~*\"@]+/', '', $w) . '*', $words));

                // MySQL FULLTEXT with multi-token LIKE fallback (menjamin hasil dalam uncommitted transaction & stop words)
                $fulltextQuery->where(function ($sub) use ($booleanTerms, $words) {
                    if (!empty($booleanTerms)) {
                        try {
                            $sub->whereRaw("MATCH(searchable_text) AGAINST(? IN BOOLEAN MODE)", [$booleanTerms]);
                        } catch (\Throwable) {
                            // Fallback jika mode FULLTEXT mengalami kendala engine
                        }
                    }
                    $sub->orWhere(function ($likeSub) use ($words) {
                        foreach ($words as $word) {
                            if (mb_strlen($word) >= 2) {
                                $likeSub->where('searchable_text', 'like', "%{$word}%");
                            }
                        }
                    });
                });
            } else {
                $fulltextQuery->where(function ($sub) use ($words, $normQuery) {
                    if (!empty($words)) {
                        foreach ($words as $word) {
                            if (mb_strlen($word) >= 2) {
                                $sub->where('searchable_text', 'like', "%{$word}%");
                            }
                        }
                    } else {
                        $sub->where('searchable_text', 'like', "%{$normQuery}%");
                    }
                });
            }

            $ftMatches = $fulltextQuery->take($clampedPageSize - $results->count())->get();
            foreach ($ftMatches as $doc) {
                $results->put($doc->id, [
                    'doc'             => $doc,
                    'match_type'      => 'fulltext_match',
                    'relevance_score' => 0.70,
                ]);
            }
        }

        // ---------------------------------------------------------------------
        // Langkah 4: Candidate Fuzzy Similarity Fallback (jika hasil < 3)
        // ---------------------------------------------------------------------
        if ($results->count() < 3 && mb_strlen($normQuery) >= 4) {
            $prefix3 = mb_substr($normQuery, 0, 3);
            $candidateQuery = $this->buildBaseScopeQuery($connection, $tabName, $identityType)
                ->where('normalized_name', 'like', "{$prefix3}%");

            if ($results->isNotEmpty()) {
                $candidateQuery->whereNotIn('id', $results->keys()->all());
            }

            $candidates = $candidateQuery->take(30)->get();
            foreach ($candidates as $cand) {
                if ($cand->normalized_name) {
                    $distance = levenshtein($cand->normalized_name, $normQuery);
                    if ($distance <= 2 || ($distance <= 3 && mb_strlen($normQuery) >= 7)) {
                        $results->put($cand->id, [
                            'doc'             => $cand,
                            'match_type'      => 'fuzzy_match',
                            'relevance_score' => 0.50,
                        ]);
                        if ($results->count() >= $clampedPageSize) break;
                    }
                }
            }
        }

        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

        $formattedResults = $results->values()->map(function ($item) use ($rawQuery) {
            return $this->formatResultItem($item['doc'], $item['match_type'], $item['relevance_score'], $rawQuery);
        })->all();

        return [
            'success'         => true,
            'query'           => $rawQuery,
            'total'           => count($formattedResults),
            'page'            => $clampedPage,
            'page_size'       => $clampedPageSize,
            'elapsed_ms'      => $elapsedMs,
            'index_freshness' => [
                'last_indexed_at' => $lastIndexedAt,
                'is_stale'        => $isStale,
            ],
            'results'         => $formattedResults,
        ];
    }

    /**
     * Membangun scope query dasar dengan isolasi brand ketat dan generasi aktif.
     */
    protected function buildBaseScopeQuery(
        SpreadsheetConnection $connection,
        ?string $tabName = null,
        ?string $identityType = null
    ) {
        $query = SpreadsheetSearchDocument::query()
            ->where('brand_id', $connection->brand_id)
            ->where('spreadsheet_connection_id', $connection->id);

        if (!empty($tabName)) {
            $query->where('tab_name', $tabName);
        }

        if (!empty($identityType)) {
            $query->where('identity_type', $identityType);
        }

        // Ambil hanya dokumen generasi terbaru aktif per tab
        $query->whereRaw('index_generation = (
            SELECT MAX(sub.index_generation) 
            FROM spreadsheet_search_documents sub 
            WHERE sub.spreadsheet_connection_id = spreadsheet_search_documents.spreadsheet_connection_id 
              AND sub.tab_name = spreadsheet_search_documents.tab_name
        )');

        return $query;
    }

    /**
     * Format item hasil pencarian untuk dikembalikan ke API.
     */
    protected function formatResultItem(SpreadsheetSearchDocument $doc, string $matchType, float $score, string $query): array
    {
        $payload = $doc->searchable_payload ?? [];
        $displayName = $doc->normalized_name ? ucwords($doc->normalized_name) : ($payload['name'] ?? '-');
        $displayContact = trim(($doc->raw_phone ?: $doc->normalized_phone ?: '') . ' ' . ($doc->normalized_email ?: ''));

        // Highlight snippet jika ada query
        $highlight = null;
        if (!empty($query)) {
            $cleanQ = preg_quote($query, '/');
            if ($doc->normalized_name && preg_match("/{$cleanQ}/i", $doc->normalized_name)) {
                $highlight = [
                    'field'   => 'name',
                    'snippet' => preg_replace("/({$cleanQ})/i", '<mark class="bg-amber-200 text-amber-900 px-0.5 rounded">$1</mark>', $displayName),
                ];
            } elseif ($doc->normalized_email && preg_match("/{$cleanQ}/i", $doc->normalized_email)) {
                $highlight = [
                    'field'   => 'email',
                    'snippet' => preg_replace("/({$cleanQ})/i", '<mark class="bg-amber-200 text-amber-900 px-0.5 rounded">$1</mark>', $doc->normalized_email),
                ];
            } elseif ($doc->raw_phone && preg_match("/{$cleanQ}/i", $doc->raw_phone)) {
                $highlight = [
                    'field'   => 'phone',
                    'snippet' => preg_replace("/({$cleanQ})/i", '<mark class="bg-amber-200 text-amber-900 px-0.5 rounded">$1</mark>', $doc->raw_phone),
                ];
            }
        }

        return [
            'id'                    => $doc->id,
            'public_uuid'           => $doc->public_uuid,
            'display_name'          => $displayName,
            'display_contact'       => $displayContact,
            'tab_name'              => $doc->tab_name,
            'source_row_number'     => $doc->source_row_number,
            'source_record_key'     => $doc->source_record_key,
            'identity_type'         => $doc->identity_type,
            'local_registration_id' => $doc->local_registration_id,
            'match_type'            => $matchType,
            'relevance_score'       => $score,
            'highlight'             => $highlight,
            'payload'               => $payload,
            'indexed_at'            => $doc->indexed_at?->toIso8601String(),
        ];
    }
}
