<?php

namespace App\Services;

use App\Models\FollowUpCycle;
use App\Models\RegistrationContactLink;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

class FollowUpSpreadsheetMappingService
{
    /**
     * Resolve the active schema version for the Follow-up spreadsheet.
     * Uses the single operational tab schema version (sentinel year = 0).
     */
    public function getSchemaVersion(): ?SpreadsheetSchemaVersion
    {
        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();

        if (!$connection) {
            return null;
        }

        return SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $connection->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Build the deterministic 21-column row payload for a FollowUpCycle.
     *
     * @param FollowUpCycle $cycle
     * @param int $rowNumber 1-based presentation index
     * @param SpreadsheetSchemaVersion|null $schema
     * @return array<string, mixed> Key-value map of exact [ sheet_header => value ]
     */
    public function buildOutboundRowPayload(
        FollowUpCycle $cycle,
        int $rowNumber = 1,
        ?SpreadsheetSchemaVersion $schema = null
    ): array {
        $schema = $schema ?? $this->getSchemaVersion();

        if (!$schema) {
            throw new DomainException('Schema versi aktif untuk Spreadsheet Follow-Up Guru/Dosen belum dikonfigurasi.');
        }

        // Eager load relations if not already loaded
        $cycle->loadMissing([
            'institution',
            'contact.institution',
            'attempts.performer',
        ]);

        $mappings = $schema->columnMappings()->orderBy('column_order', 'asc')->get();

        if ($mappings->isEmpty()) {
            throw new DomainException('Column mappings untuk skema Follow-Up Guru/Dosen belum didefinisikan.');
        }

        $payload = [];

        foreach ($mappings as $mapping) {
            $payload[$mapping->sheet_header] = $this->resolveColumnValue($cycle, $mapping, $rowNumber);
        }

        return $payload;
    }

    /**
     * Resolve a single column value deterministically from the FollowUpCycle domain.
     */
    public function resolveColumnValue(
        FollowUpCycle $cycle,
        SpreadsheetColumnMapping $mapping,
        int $rowNumber = 1
    ): mixed {
        return match ($mapping->target_field) {
            'row_number' => $rowNumber,
            'cycle_year' => $cycle->cycle_year,
            'name' => match ($mapping->target_entity) {
                'institution' => $cycle->institution?->name ?? $cycle->contact?->institution?->name ?? '-',
                'institution_contact' => $cycle->contact?->name ?? '-',
                default => null,
            },
            'phone' => $cycle->contact?->phone ?? '-',
            'verification_status' => $this->resolveContactStatus($cycle),
            'attempt_1_summary' => $this->resolveAttemptSummary($cycle, 1),
            'attempt_2_summary' => $this->resolveAttemptSummary($cycle, 2),
            'attempt_3_summary' => $this->resolveAttemptSummary($cycle, 3),
            'attempt_1_performer_name' => $this->resolveAttemptPerformerName($cycle, 1),
            'attempt_2_performer_name' => $this->resolveAttemptPerformerName($cycle, 2),
            'attempt_3_performer_name' => $this->resolveAttemptPerformerName($cycle, 3),
            'internship_period_summary' => $this->resolveInternshipPeriodSummary($cycle),
            'final_result_label' => $this->resolveFinalResultLabel($cycle),
            'final_notes' => $cycle->final_notes ?? null,
            // S9D/S9E Technical metadata guardrails
            'external_sync_id' => null,
            'id' => match ($mapping->target_entity) {
                'institution' => (string) ($cycle->institution_id ?? $cycle->contact?->institution_id),
                'institution_contact' => (string) $cycle->institution_contact_id,
                'followup_cycle' => (string) $cycle->id,
                default => null,
            },
            'sync_status' => 'pending',
            'last_synced_at' => null,
            default => $mapping->default_value,
        };
    }

    /**
     * Resolve contact verification and active status label.
     */
    protected function resolveContactStatus(FollowUpCycle $cycle): string
    {
        $contact = $cycle->contact;

        if (!$contact) {
            return '-';
        }

        if (!$contact->is_active) {
            return 'Nonaktif';
        }

        return $contact->is_verified ? 'Terverifikasi' : 'Kandidat';
    }

    /**
     * Resolve formatted attempt summary string (Date [Channel] - Result (Notes)).
     */
    protected function resolveAttemptSummary(FollowUpCycle $cycle, int $attemptNumber): ?string
    {
        $attempt = $cycle->attempts->firstWhere('attempt_number', $attemptNumber);

        if (!$attempt) {
            return null;
        }

        $parts = [];

        if ($attempt->followed_up_at) {
            $parts[] = $attempt->followed_up_at->format('Y-m-d');
        }

        $parts[] = '[' . $attempt->channelLabel() . ']';
        $parts[] = $attempt->resultLabel();

        if (!empty($attempt->notes)) {
            $parts[] = '(' . trim($attempt->notes) . ')';
        } elseif (!empty($attempt->response_text)) {
            $parts[] = '(' . trim($attempt->response_text) . ')';
        }

        return implode(' ', $parts);
    }

    /**
     * Resolve HR staff performer name for a specific attempt (supports soft-deleted user history).
     */
    protected function resolveAttemptPerformerName(FollowUpCycle $cycle, int $attemptNumber): ?string
    {
        $attempt = $cycle->attempts->firstWhere('attempt_number', $attemptNumber);

        if (!$attempt) {
            return null;
        }

        return $attempt->performer?->name ?? null;
    }

    /**
     * Resolve linked interns timeframe summary without duplicating the cycle record.
     */
    protected function resolveInternshipPeriodSummary(FollowUpCycle $cycle): ?string
    {
        if (!$cycle->institution_contact_id) {
            return null;
        }

        $links = RegistrationContactLink::with('internshipRegistration')
            ->where('institution_contact_id', $cycle->institution_contact_id)
            ->get();

        $registrations = $links->pluck('internshipRegistration')->filter();

        if ($registrations->isEmpty()) {
            return null;
        }

        $periods = [];

        foreach ($registrations as $reg) {
            $startDate = $reg->start_date instanceof \Carbon\CarbonInterface
                ? $reg->start_date
                : ($reg->start_date ? \Carbon\Carbon::parse($reg->start_date) : null);

            $endDate = $reg->end_date instanceof \Carbon\CarbonInterface
                ? $reg->end_date
                : ($reg->end_date ? \Carbon\Carbon::parse($reg->end_date) : null);

            if ($startDate && $endDate) {
                $periods[] = $startDate->format('Y-m-d') . ' s/d ' . $endDate->format('Y-m-d');
            } elseif ($startDate) {
                $dur = $reg->planned_duration ? " ({$reg->planned_duration})" : '';
                $periods[] = 'Mulai ' . $startDate->format('Y-m-d') . $dur;
            } elseif ($reg->planned_duration) {
                $periods[] = $reg->planned_duration;
            }
        }

        $uniquePeriods = array_unique(array_filter($periods));
        $count = $registrations->count();

        if (empty($uniquePeriods)) {
            return "{$count} Pemagang terdaftar";
        }

        return implode(', ', $uniquePeriods) . " ({$count} Pemagang)";
    }

    /**
     * Resolve canonical domain result label for S7/S8 semantics.
     */
    protected function resolveFinalResultLabel(FollowUpCycle $cycle): string
    {
        if (!empty($cycle->final_result)) {
            return match ($cycle->final_result) {
                FollowUpCycle::RESULT_WILLING => 'Bersedia Kerja Sama',
                FollowUpCycle::RESULT_DECLINED => 'Menolak',
                FollowUpCycle::RESULT_COOPERATION_STOPPED => 'Kerja Sama Berhenti',
                FollowUpCycle::RESULT_NO_RESPONSE => 'Tidak Ada Respons',
                FollowUpCycle::RESULT_CALL_BACK => 'Minta Dihubungi Lagi',
                default => ucfirst($cycle->final_result),
            };
        }

        // When cycle is still open/in-progress, report latest attempt state or active status
        if ($cycle->attempts->isNotEmpty()) {
            $latestAttempt = $cycle->attempts->sortByDesc('attempt_number')->first();
            return match ($latestAttempt->result) {
                'responded' => 'Direspons Positif',
                'call_back' => 'Minta Dihubungi Lagi',
                'no_answer' => 'Tidak Menjawab',
                'busy' => 'Sedang Sibuk',
                'wrong_number' => 'Nomor Tidak Valid',
                'declined' => 'Menolak',
                default => $cycle->statusLabel(),
            };
        }

        return $cycle->statusLabel();
    }

    /**
     * Validate compatibility between spreadsheet sheet headers and database column mappings.
     *
     * @param SpreadsheetSchemaVersion $schema
     * @param array<int, string> $sheetHeaders
     * @return array{is_compatible: bool, missing_headers: array<int, string>, misordered_headers: array<int, array{expected_order: int, header: string, actual_order: int}>}
     */
    public function validateHeaderCompatibility(
        SpreadsheetSchemaVersion $schema,
        array $sheetHeaders
    ): array {
        $expectedMappings = $schema->columnMappings()->orderBy('column_order', 'asc')->get();

        $missingHeaders = [];
        $misorderedHeaders = [];

        foreach ($expectedMappings as $mapping) {
            $actualIndex = array_search($mapping->sheet_header, $sheetHeaders, true);

            if ($actualIndex === false) {
                $missingHeaders[] = $mapping->sheet_header;
            } else {
                $actualOrder = $actualIndex + 1; // 1-based order
                if ($actualOrder !== $mapping->column_order) {
                    $misorderedHeaders[] = [
                        'expected_order' => $mapping->column_order,
                        'header'         => $mapping->sheet_header,
                        'actual_order'   => $actualOrder,
                    ];
                }
            }
        }

        $isCompatible = empty($missingHeaders) && empty($misorderedHeaders) && (count($sheetHeaders) >= count($expectedMappings));

        return [
            'is_compatible'      => $isCompatible,
            'missing_headers'    => $missingHeaders,
            'misordered_headers' => $misorderedHeaders,
        ];
    }
}
