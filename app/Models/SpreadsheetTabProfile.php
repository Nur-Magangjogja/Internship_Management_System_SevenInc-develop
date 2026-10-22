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
     * Resolve default header row suggestion based on convention if no profile exists.
     * Note: 2026/year => 12 and other => 1 is only an initial suggestion;
     * explicit profile always takes precedence.
     */
    public static function defaultHeaderRowSuggestion(string $tabName, string $connectionKey = ''): int
    {
        $clean = trim($tabName);
        if (preg_match('/^\d{4}$/', $clean)) {
            return 12;
        }
        return 1;
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
            if ($dirty) {
                $profile->save();
            }
            return $profile;
        }

        $headerRow = $explicitHeaderRow ?? static::defaultHeaderRowSuggestion($tabName, $connection->connection_key);
        $dataStartRow = $explicitDataStartRow ?? ($headerRow + 1);

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
