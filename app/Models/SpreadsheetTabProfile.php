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
        'tab_name',
        'header_row',
        'data_start_row',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'sheet_id'       => 'integer',
        'header_row'     => 'integer',
        'data_start_row' => 'integer',
        'is_active'      => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'spreadsheet_connection_id');
    }

    /**
     * Determine if a tab is a yearly tab (e.g. 2026, 2027) or TEMPLATE_TAHUN.
     */
    public static function isYearlyOrTemplateTab(string $tabName): bool
    {
        $clean = trim($tabName);
        return (bool) preg_match('/^\d{4}$/', $clean) || $clean === 'TEMPLATE_TAHUN';
    }

    /**
     * Resolve default header row suggestion based on convention if no profile exists.
     * Note: 2026/year and TEMPLATE_TAHUN => 12; other tabs (e.g. Petunjuk) => 1.
     * Explicit profile always takes precedence.
     */
    public static function defaultHeaderRowSuggestion(string $tabName, string $connectionKey = ''): int
    {
        if (static::isYearlyOrTemplateTab($tabName)) {
            return 12;
        }
        return 1;
    }

    /**
     * Resolve default data start row suggestion based on convention if no profile exists.
     * Note: 2026/year and TEMPLATE_TAHUN => 13; other tabs (e.g. Petunjuk) => 2.
     */
    public static function defaultDataStartRowSuggestion(string $tabName, string $connectionKey = '', ?int $headerRow = null): int
    {
        $header = $headerRow ?? static::defaultHeaderRowSuggestion($tabName, $connectionKey);
        return $header + 1;
    }

    /**
     * Get or create profile for a tab.
     */
    public static function resolveProfile(
        SpreadsheetConnection $connection,
        int|string $sheetId,
        string $tabName,
        ?int $explicitHeaderRow = null,
        ?int $explicitDataStartRow = null
    ): self {
        $numericSheetId = (int) $sheetId;

        if ($explicitHeaderRow !== null && $explicitHeaderRow < 1) {
            throw new \InvalidArgumentException('header_row harus lebih besar atau sama dengan 1.');
        }

        if ($explicitHeaderRow !== null && $explicitDataStartRow !== null && $explicitDataStartRow <= $explicitHeaderRow) {
            throw new \InvalidArgumentException('data_start_row harus lebih besar dari header_row.');
        }

        $profile = static::where('spreadsheet_connection_id', $connection->id)
            ->where('sheet_id', $numericSheetId)
            ->first();

        if ($profile) {
            $dirty = false;
            if ($tabName !== '' && $profile->tab_name !== $tabName) {
                $profile->tab_name = $tabName;
                $dirty = true;
            }
            if ($explicitHeaderRow !== null && $profile->header_row !== $explicitHeaderRow) {
                $profile->header_row = $explicitHeaderRow;
                $dirty = true;
            }
            if ($explicitDataStartRow !== null && $profile->data_start_row !== $explicitDataStartRow) {
                if ($explicitDataStartRow <= $profile->header_row) {
                    throw new \InvalidArgumentException('data_start_row harus lebih besar dari header_row.');
                }
                $profile->data_start_row = $explicitDataStartRow;
                $dirty = true;
            }
            // Auto-heal legacy TEMPLATE_TAHUN profile if still defaulting to 1/2 without explicit override
            if ($explicitHeaderRow === null && $explicitDataStartRow === null
                && $profile->tab_name === 'TEMPLATE_TAHUN'
                && $profile->header_row === 1
                && $profile->data_start_row === 2
            ) {
                $profile->header_row = 12;
                $profile->data_start_row = 13;
                $dirty = true;
            }
            if ($dirty) {
                $profile->save();
            }
            return $profile;
        }

        $headerRow = $explicitHeaderRow ?? static::defaultHeaderRowSuggestion($tabName, $connection->connection_key);
        $dataStartRow = $explicitDataStartRow ?? static::defaultDataStartRowSuggestion($tabName, $connection->connection_key, $headerRow);

        if ($dataStartRow <= $headerRow) {
            throw new \InvalidArgumentException('data_start_row harus lebih besar dari header_row.');
        }

        return static::create([
            'spreadsheet_connection_id' => $connection->id,
            'sheet_id'                  => $numericSheetId,
            'tab_name'                  => $tabName,
            'header_row'                => $headerRow,
            'data_start_row'            => $dataStartRow,
            'is_active'                 => true,
        ]);
    }
}
