<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'company_address',
        'logo',
        'internship_certificate_bg',
        'webinar_certificate_bg',
        'membercard_bg',
        'signatory_name',
        'signatory_email',
        'signatory_position',
        'signature',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }

        if (str_starts_with($this->logo, 'http://') || str_starts_with($this->logo, 'https://') || str_starts_with($this->logo, '/')) {
            return $this->logo;
        }

        if (str_starts_with($this->logo, 'images/')) {
            return '/' . $this->logo;
        }

        return \Illuminate\Support\Facades\Storage::url($this->logo);
    }

    public function getMembercardBgUrlAttribute(): ?string
    {
        if ($this->membercard_bg) {
            return \Illuminate\Support\Facades\Storage::url($this->membercard_bg);
        }
        return null;
    }

    /**
     * Mengembalikan tema visual styling khusus untuk brand jika belum ada gambar background.
     */
    public function getMembercardThemeAttribute(): array
    {
        $key = strtolower(trim($this->code ?? '')) . ' ' . strtolower(trim($this->name ?? ''));

        if (str_contains($key, 'seven') || str_contains($key, 'si') || str_contains($key, 'svn')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #052317 0%, #0c432f 50%, #03170e 100%)',
                'accent'        => '#E6CA65',
                'accent_light'  => '#FDF6D8',
                'accent_border' => 'rgba(230, 202, 101, 0.35)',
                'pill_bg'       => 'rgba(230, 202, 101, 0.12)',
                'pill_border'   => 'rgba(230, 202, 101, 0.3)',
                'chip'          => 'gold',
                'badge_text'    => 'SEVEN INC ALUMNI',
            ];
        }

        if (str_contains($key, 'alona') || str_contains($key, 'alb')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #180d39 0%, #2b145e 50%, #0d0621 100%)',
                'accent'        => '#F3A7C4',
                'accent_light'  => '#FDE8F1',
                'accent_border' => 'rgba(243, 167, 196, 0.35)',
                'pill_bg'       => 'rgba(243, 167, 196, 0.12)',
                'pill_border'   => 'rgba(243, 167, 196, 0.3)',
                'chip'          => 'silver',
                'badge_text'    => 'ALONA ALUMNI',
            ];
        }

        if (str_contains($key, 'area') || str_contains($key, 'kerja')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #0a192f 0%, #112d57 50%, #050c18 100%)',
                'accent'        => '#64D2FF',
                'accent_light'  => '#E0F7FF',
                'accent_border' => 'rgba(100, 210, 255, 0.35)',
                'pill_bg'       => 'rgba(100, 210, 255, 0.12)',
                'pill_border'   => 'rgba(100, 210, 255, 0.3)',
                'chip'          => 'silver',
                'badge_text'    => 'AREAKERJA ALUMNI',
            ];
        }

        if (str_contains($key, 'jogja') || str_contains($key, 'magang')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #062624 0%, #0d4a46 50%, #031716 100%)',
                'accent'        => '#4ADE80',
                'accent_light'  => '#DCFCE7',
                'accent_border' => 'rgba(74, 222, 128, 0.35)',
                'pill_bg'       => 'rgba(74, 222, 128, 0.12)',
                'pill_border'   => 'rgba(74, 222, 128, 0.3)',
                'chip'          => 'gold',
                'badge_text'    => 'MAGANGJOGJA ALUMNI',
            ];
        }

        if (str_contains($key, 'titip') || str_contains($key, 'sini')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #2a1608 0%, #46250e 50%, #170b04 100%)',
                'accent'        => '#FBBF24',
                'accent_light'  => '#FEF3C7',
                'accent_border' => 'rgba(251, 191, 36, 0.35)',
                'pill_bg'       => 'rgba(251, 191, 36, 0.12)',
                'pill_border'   => 'rgba(251, 191, 36, 0.3)',
                'chip'          => 'gold',
                'badge_text'    => 'TITIPSINI ALUMNI',
            ];
        }

        // Fallback dinamis jika ada brand kustom lainnya
        $hash = crc32($key);
        $hue1 = abs($hash % 360);
        $hue2 = ($hue1 + 30) % 360;

        return [
            'gradient'      => "linear-gradient(135deg, hsl({$hue1}, 45%, 10%) 0%, hsl({$hue2}, 50%, 18%) 50%, hsl({$hue1}, 55%, 7%) 100%)",
            'accent'        => '#E2E8F0',
            'accent_light'  => '#F8FAFC',
            'accent_border' => 'rgba(226, 232, 240, 0.35)',
            'pill_bg'       => 'rgba(255, 255, 255, 0.10)',
            'pill_border'   => 'rgba(255, 255, 255, 0.25)',
            'chip'          => 'silver',
            'badge_text'    => strtoupper($this->name ?: 'ALUMNI MEMBER'),
        ];
    }
}
