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
        'registration_host',
        'primary_domain',
        'is_default',
        'company_address',
        'logo',
        'internship_certificate_bg',
        'webinar_certificate_bg',
        'membercard_bg',
        'signatory_name',
        'signatory_email',
        'signatory_position',
        'signature',
        'stamp',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Dapatkan brand default sistem (fallback ke SEVEN INC jika belum diset).
     */
    public static function getDefaultBrand(): ?self
    {
        return static::where('is_default', true)->first()
            ?: static::where('code', 'SI')->first()
            ?: static::first();
    }

    /**
     * Tetapkan brand ini sebagai Brand Default sistem.
     */
    public function makeDefault(): void
    {
        static::where('is_default', true)->where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    public function spreadsheetConnections()
    {
        return $this->hasMany(\App\Models\SpreadsheetConnection::class, 'brand_id');
    }

    public function divisions()
    {
        return $this->belongsToMany(\App\Models\Division::class, 'brand_divisions', 'brand_id', 'division_id')
            ->withTimestamps();
    }

    public function internshipRegistrations()
    {
        return $this->hasMany(\App\Models\InternshipRegistration::class, 'brand_id');
    }

    public function users()
    {
        return $this->hasMany(\App\Models\User::class, 'brand_id');
    }

    public function registrationSpreadsheetConnection()
    {
        return $this->hasOne(\App\Models\SpreadsheetConnection::class, 'brand_id')
            ->where('connection_key', \App\Models\SpreadsheetConnection::KEY_REGISTRATION);
    }

    public function getStampUrlAttribute(): ?string
    {
        if (!$this->stamp) {
            return null;
        }

        if (str_starts_with($this->stamp, 'http://') || str_starts_with($this->stamp, 'https://') || str_starts_with($this->stamp, '/')) {
            return $this->stamp;
        }

        if (str_starts_with($this->stamp, 'images/')) {
            return '/' . $this->stamp;
        }

        return \Illuminate\Support\Facades\Storage::url($this->stamp);
    }

    public function getSignatureUrlAttribute(): ?string
    {
        if (!$this->signature) {
            return null;
        }

        if (str_starts_with($this->signature, 'http://') || str_starts_with($this->signature, 'https://') || str_starts_with($this->signature, '/')) {
            return $this->signature;
        }

        if (str_starts_with($this->signature, 'images/')) {
            return '/' . $this->signature;
        }

        return \Illuminate\Support\Facades\Storage::url($this->signature);
    }

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

    public function getInternshipCertificateBgUrlAttribute(): ?string
    {
        if (!$this->internship_certificate_bg) {
            return null;
        }

        if (str_starts_with($this->internship_certificate_bg, 'http://') || str_starts_with($this->internship_certificate_bg, 'https://') || str_starts_with($this->internship_certificate_bg, '/')) {
            return $this->internship_certificate_bg;
        }

        if (str_starts_with($this->internship_certificate_bg, 'images/')) {
            return '/' . $this->internship_certificate_bg;
        }

        return \Illuminate\Support\Facades\Storage::url($this->internship_certificate_bg);
    }

    public function getWebinarCertificateBgUrlAttribute(): ?string
    {
        if (!$this->webinar_certificate_bg) {
            return null;
        }

        if (str_starts_with($this->webinar_certificate_bg, 'http://') || str_starts_with($this->webinar_certificate_bg, 'https://') || str_starts_with($this->webinar_certificate_bg, '/')) {
            return $this->webinar_certificate_bg;
        }

        if (str_starts_with($this->webinar_certificate_bg, 'images/')) {
            return '/' . $this->webinar_certificate_bg;
        }

        return \Illuminate\Support\Facades\Storage::url($this->webinar_certificate_bg);
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

        if (str_contains($key, 'titip') || str_contains($key, 'sini') || str_contains($key, 'ts')) {
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

        if (str_contains($key, 'republik') || str_contains($key, 'rw')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #091e3a 0%, #173865 50%, #061325 100%)',
                'accent'        => '#38BDF8',
                'accent_light'  => '#E0F2FE',
                'accent_border' => 'rgba(56, 189, 248, 0.35)',
                'pill_bg'       => 'rgba(56, 189, 248, 0.12)',
                'pill_border'   => 'rgba(56, 189, 248, 0.3)',
                'chip'          => 'silver',
                'badge_text'    => 'REPUBLIK WEB ALUMNI',
            ];
        }

        if (str_contains($key, 'kamar') || str_contains($key, 'akm')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #2b112c 0%, #4a154b 50%, #19081a 100%)',
                'accent'        => '#E879F9',
                'accent_light'  => '#FDF4FF',
                'accent_border' => 'rgba(232, 121, 249, 0.35)',
                'pill_bg'       => 'rgba(232, 121, 249, 0.12)',
                'pill_border'   => 'rgba(232, 121, 249, 0.3)',
                'chip'          => 'silver',
                'badge_text'    => 'ADA KAMAR ALUMNI',
            ];
        }

        if (str_contains($key, 'tanah') || str_contains($key, 'tt')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #271c0c 0%, #443015 50%, #150f06 100%)',
                'accent'        => '#D97706',
                'accent_light'  => '#FEF3C7',
                'accent_border' => 'rgba(217, 119, 6, 0.35)',
                'pill_bg'       => 'rgba(217, 119, 6, 0.12)',
                'pill_border'   => 'rgba(217, 119, 6, 0.3)',
                'chip'          => 'gold',
                'badge_text'    => 'TUAN TANAH ALUMNI',
            ];
        }

        if (str_contains($key, 'makeover') || str_contains($key, 'mo')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #2d0a1e 0%, #4c1133 50%, #1a0511 100%)',
                'accent'        => '#FB7185',
                'accent_light'  => '#FFE4E6',
                'accent_border' => 'rgba(251, 113, 133, 0.35)',
                'pill_bg'       => 'rgba(251, 113, 133, 0.12)',
                'pill_border'   => 'rgba(251, 113, 133, 0.3)',
                'chip'          => 'silver',
                'badge_text'    => 'MAKEOVER ALUMNI',
            ];
        }

        if (str_contains($key, 'bimbel') || str_contains($key, 'bc')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)',
                'accent'        => '#2DD4BF',
                'accent_light'  => '#CCFBF1',
                'accent_border' => 'rgba(45, 212, 191, 0.35)',
                'pill_bg'       => 'rgba(45, 212, 191, 0.12)',
                'pill_border'   => 'rgba(45, 212, 191, 0.3)',
                'chip'          => 'gold',
                'badge_text'    => 'BIMBEL CERDAS ALUMNI',
            ];
        }

        if (str_contains($key, 'las') || str_contains($key, 'tl')) {
            return [
                'gradient'      => 'linear-gradient(135deg, #26150b 0%, #452410 50%, #190c05 100%)',
                'accent'        => '#F97316',
                'accent_light'  => '#FFEDD5',
                'accent_border' => 'rgba(249, 115, 22, 0.35)',
                'pill_bg'       => 'rgba(249, 115, 22, 0.12)',
                'pill_border'   => 'rgba(249, 115, 22, 0.3)',
                'chip'          => 'gold',
                'badge_text'    => 'TUKANG LAS ALUMNI',
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
