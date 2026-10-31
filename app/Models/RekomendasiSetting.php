<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RekomendasiSetting extends Model
{
    protected $table = 'rekomendasi_settings';

    protected $fillable = [
        'company_name',
        'company_address',
        'company_city',
        'company_phone',
        'company_postal_code',
        'leader_name',
        'leader_title',
        'company_brand',
        'body_template',
        'logo_path',
        'stamp_path',
    ];

    /**
     * Default body template dengan placeholder.
     * Placeholder: {nama}, {divisi}, {mulai}, {selesai}, {durasi}, {instansi}, {nim}
     */
    public static function defaultBodyTemplate(): string
    {
        return 'Yang bersangkutan telah melaksanakan magang di {company_brand} sejak {mulai} sampai dengan {selesai}. Selama masa magang, saudara/i {nama} menunjukkan performa luar biasa, kemampuan komunikasi yang efektif, serta komitmen tinggi dalam menyelesaikan setiap proyek yang diberikan.';
    }
}
