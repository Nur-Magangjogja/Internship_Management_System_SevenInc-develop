<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSubmitSetting extends Model
{
    use HasFactory;

    protected $table = 'form_submit_settings';

    protected $fillable = [
        'year',
        'brand_id',
        'section_title',
        'section_subtitle',
        'show_notice_box',
        'notice_title',
        'notice_text',
        'admin_wa_number',
        'admin_wa_message',
        'submit_button_text',
        'save_draft_enabled',
    ];

    protected $casts = [
        'year'               => 'integer',
        'show_notice_box'    => 'boolean',
        'save_draft_enabled' => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * Default values for submit settings.
     */
    public static function defaultSettings(int $year = 2026, ?int $brandId = null): array
    {
        return [
            'year'               => $year,
            'brand_id'           => $brandId,
            'section_title'      => 'Finalisasi & Submit',
            'section_subtitle'   => 'Tinjau ketentuan program magang dan kirim formulir pendaftaran Anda.',
            'show_notice_box'    => true,
            'notice_title'       => 'Perhatian:',
            'notice_text'        => 'Program magang ini bersifat unpaid / tidak bergaji.',
            'admin_wa_number'    => '0895 2900 2944',
            'admin_wa_message'   => 'SAYA SUDAH ISI FORM',
            'submit_button_text' => 'Kirim Pendaftaran',
            'save_draft_enabled' => true,
        ];
    }

    /**
     * Resolve settings for a specific year and optional brand.
     */
    public static function forYear(int $year, ?int $brandId = null): self
    {
        if ($brandId) {
            $brandSpecific = static::where('year', $year)->where('brand_id', $brandId)->first();
            if ($brandSpecific) {
                return $brandSpecific;
            }
        }

        $general = static::where('year', $year)->whereNull('brand_id')->first();
        if ($general) {
            return $general;
        }

        // Fallback: Check if there's any setting for this year
        $anyForYear = static::where('year', $year)->first();
        if ($anyForYear) {
            return $anyForYear;
        }

        // Fallback to previous year setting if available
        $prevYear = static::where('year', $year - 1)->whereNull('brand_id')->first();
        if ($prevYear) {
            $attributes = $prevYear->toArray();
            unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
            $attributes['year'] = $year;
            $attributes['brand_id'] = $brandId;
            return new self($attributes);
        }

        return new self(static::defaultSettings($year, $brandId));
    }
}
