<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpreadsheetTabProfile extends Model
{
    use HasFactory;

    protected $table = 'spreadsheet_tab_profiles';

    protected $fillable = [
        'spreadsheet_connection_id',
        'sheet_id',
        'table_identifier',
        'tab_name',
        'table_range',
        'header_row',
        'header_row_count',
        'data_start_row',
        'orientation',
        'source_type',
        'status_source_type',
        'status_column_letter',
        'column_mapping',
        'field_ownership_policies',
        'status_mapping',
        'last_schema_hash',
        'mapping_version',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'sheet_id'                 => 'integer',
        'header_row'               => 'integer',
        'header_row_count'         => 'integer',
        'data_start_row'           => 'integer',
        'column_mapping'           => 'array',
        'field_ownership_policies' => 'array',
        'status_mapping'           => 'array',
        'mapping_version'          => 'integer',
        'is_active'                => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }

    /**
     * Backward-compatible helper for legacy year/template tabs.
     */
    public static function isYearlyOrTemplateTab(string $tabName): bool
    {
        $clean = trim($tabName);
        return (bool) preg_match('/^\d{4}$/', $clean) || $clean === 'TEMPLATE_TAHUN';
    }

    /**
     * Resolve default header row suggestion based on convention if no profile exists.
     * Note: 2026/year and TEMPLATE_TAHUN => 12; Pendaftaran/Magang/Peserta => 10; Form Responses => 7; other tabs => 1.
     */
    public static function defaultHeaderRowSuggestion(string $tabName, string $connectionKey = ''): int
    {
        if (static::isYearlyOrTemplateTab($tabName)) {
            return 12;
        }

        $clean = strtolower(trim($tabName));
        if (preg_match('/pendaftaran|registration|registrasi|magang|peserta/i', $clean)) {
            return 10;
        }

        if (preg_match('/response/i', $clean)) {
            return 7;
        }

        return 1;
    }

    /**
     * Resolve default data start row suggestion based on convention if no profile exists.
     */
    public static function defaultDataStartRowSuggestion(string $tabName, string $connectionKey = '', ?int $headerRow = null): int
    {
        $header = $headerRow ?? static::defaultHeaderRowSuggestion($tabName, $connectionKey);
        return $header + 1;
    }

    /**
     * Resolve or create stable profile with table_identifier support for multi-table tabs.
     */
    public static function resolveStableProfile(
        SpreadsheetConnection $connection,
        int|string $sheetId,
        string $tabName,
        string $tableIdentifier = 'default',
        array $attributes = []
    ): self {
        $numericSheetId = (int) $sheetId;
        $tableId = trim($tableIdentifier) !== '' ? trim($tableIdentifier) : 'default';

        $profile = static::where('spreadsheet_connection_id', $connection->id)
            ->where('sheet_id', $numericSheetId)
            ->where('table_identifier', $tableId)
            ->first();

        if ($profile) {
            $dirty = false;
            if ($tabName !== '' && $profile->tab_name !== $tabName) {
                $profile->tab_name = $tabName;
                $dirty = true;
            }

            foreach ($attributes as $key => $val) {
                if (in_array($key, $profile->getFillable(), true) && $val !== null) {
                    if ($profile->{$key} !== $val) {
                        $profile->{$key} = $val;
                        $dirty = true;
                    }
                }
            }

            if ($dirty) {
                $profile->save();
            }
            return $profile;
        }

        $headerRow = $attributes['header_row'] ?? static::defaultHeaderRowSuggestion($tabName, $connection->connection_key);
        $dataStartRow = $attributes['data_start_row'] ?? static::defaultDataStartRowSuggestion($tabName, $connection->connection_key, $headerRow);

        $payload = array_merge([
            'spreadsheet_connection_id' => $connection->id,
            'sheet_id'                  => $numericSheetId,
            'table_identifier'          => $tableId,
            'tab_name'                  => $tabName,
            'header_row'                => $headerRow,
            'header_row_count'          => $attributes['header_row_count'] ?? 1,
            'data_start_row'            => $dataStartRow,
            'orientation'               => $attributes['orientation'] ?? 'row_per_intern',
            'source_type'               => $attributes['source_type'] ?? 'registration_form',
            'status_source_type'        => $attributes['status_source_type'] ?? 'none',
            'status_column_letter'      => $attributes['status_column_letter'] ?? null,
            'is_active'                 => true,
        ], $attributes);

        return static::create($payload);
    }

    /**
     * Legacy profile resolver (backwards compatibility wrapper).
     */
    public static function resolveProfile(
        SpreadsheetConnection $connection,
        int|string $sheetId,
        string $tabName,
        ?int $explicitHeaderRow = null,
        ?int $explicitDataStartRow = null
    ): self {
        $attrs = [];
        if ($explicitHeaderRow !== null) $attrs['header_row'] = $explicitHeaderRow;
        if ($explicitDataStartRow !== null) $attrs['data_start_row'] = $explicitDataStartRow;

        return static::resolveStableProfile(
            connection: $connection,
            sheetId: $sheetId,
            tabName: $tabName,
            tableIdentifier: 'default',
            attributes: $attrs
        );
    }

    /**
     * Deteksi pergeseran skema (Schema Drift & Column Shift Protection).
     * Membandingkan mapping kolom tersimpan dengan kolom aktual yang baru dipindai.
     *
     * @param array<int, array<string, mixed>> $currentDiscoveredColumns
     * @return array{has_drift: bool, requires_review: bool, confidence_score: float, shifted_columns: array, new_columns: array, missing_columns: array}
     */
    public function detectSchemaDrift(array $currentDiscoveredColumns): array
    {
        $savedMapping = $this->column_mapping ?: [];
        if (empty($savedMapping)) {
            return [
                'has_drift'        => false,
                'requires_review'  => false,
                'confidence_score' => 1.0,
                'shifted_columns'  => [],
                'new_columns'      => [],
                'missing_columns'  => [],
            ];
        }

        $currentByLabel = [];
        $currentByCoord = [];
        foreach ($currentDiscoveredColumns as $c) {
            $lbl = strtolower(trim((string) ($c['header_name'] ?? '')));
            $colLetter = strtoupper(trim((string) ($c['col_letter'] ?? '')));
            if ($lbl !== '') {
                $currentByLabel[$lbl] = $c;
            }
            if ($colLetter !== '') {
                $currentByCoord[$colLetter] = $c;
            }
        }

        $shifted = [];
        $missing = [];
        $totalMapped = 0;
        $matchedScore = 0;

        foreach ($savedMapping as $origCol) {
            $origLetter = strtoupper(trim((string) ($origCol['col_letter'] ?? '')));
            $origLabel = strtolower(trim((string) ($origCol['header_name'] ?? '')));
            if ($origLabel === '') continue;

            $totalMapped++;

            // Cek apakah di koordinat yang sama labelnya masih persis
            if (isset($currentByCoord[$origLetter])) {
                $currAtCoordLabel = strtolower(trim((string) ($currentByCoord[$origLetter]['header_name'] ?? '')));
                if ($currAtCoordLabel === $origLabel) {
                    $matchedScore += 1.0;
                    continue;
                }
            }

            // Jika label sama tapi berpindah koordinat (misal disisipkan kolom baru)
            if (isset($currentByLabel[$origLabel])) {
                $newLetter = strtoupper(trim((string) ($currentByLabel[$origLabel]['col_letter'] ?? '')));
                if ($newLetter !== $origLetter) {
                    $shifted[] = [
                        'label'       => $origCol['header_name'],
                        'old_letter'  => $origLetter,
                        'new_letter'  => $newLetter,
                        'field_key'   => $origCol['field_key'] ?? null,
                    ];
                    $matchedScore += 0.7; // parsial confidence karena bergeser
                    continue;
                }
            }

            // Kolom tidak ditemukan lagi
            $missing[] = [
                'label'      => $origCol['header_name'],
                'old_letter' => $origLetter,
                'field_key'  => $origCol['field_key'] ?? null,
            ];
        }

        $newCols = [];
        foreach ($currentDiscoveredColumns as $c) {
            $lbl = strtolower(trim((string) ($c['header_name'] ?? '')));
            if ($lbl === '') continue;
            $foundInSaved = false;
            foreach ($savedMapping as $origCol) {
                if (strtolower(trim((string) ($origCol['header_name'] ?? ''))) === $lbl) {
                    $foundInSaved = true;
                    break;
                }
            }
            if (!$foundInSaved) {
                $newCols[] = [
                    'col_letter'  => $c['col_letter'] ?? '',
                    'header_name' => $c['header_name'] ?? '',
                ];
            }
        }

        $confidence = $totalMapped > 0 ? round($matchedScore / $totalMapped, 2) : 1.0;
        $hasDrift = count($shifted) > 0 || count($missing) > 0 || count($newCols) > 0;
        $requiresReview = $hasDrift && ($confidence < 0.9 || count($missing) > 0 || count($shifted) > 0);

        return [
            'has_drift'        => $hasDrift,
            'requires_review'  => $requiresReview,
            'confidence_score' => $confidence,
            'shifted_columns'  => $shifted,
            'new_columns'      => $newCols,
            'missing_columns'  => $missing,
        ];
    }
}
