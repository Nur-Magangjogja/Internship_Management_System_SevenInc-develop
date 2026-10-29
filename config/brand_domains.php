<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Brand Domain and Host Registry
    |--------------------------------------------------------------------------
    |
    | Metadata seed/kompatibilitas untuk 11 brand resmi historis.
    | Runtime host resolution dan TrustHosts menggunakan BrandHostRegistryService
    | berbasis database/cache; daftar di sini bukan sumber otoritatif host aktif.
    |
    */

    'default_brand_code' => 'SI',

    'hosts' => [
        'daftar.republikweb.net' => [
            'code'           => 'RW',
            'name'           => 'Republik Web',
            'primary_domain' => 'republikweb.net',
            'badge_bg'       => 'bg-blue-50',
            'badge_border'   => 'border-blue-200',
            'badge_text'     => 'text-blue-700',
        ],
        'daftar.adakamar.id' => [
            'code'           => 'AKM',
            'name'           => 'Ada Kamar',
            'primary_domain' => 'adakamar.id',
            'badge_bg'       => 'bg-teal-50',
            'badge_border'   => 'border-teal-200',
            'badge_text'     => 'text-teal-700',
        ],
        'daftar.magangjogja.com' => [
            'code'           => 'MJ',
            'name'           => 'Magang Jogja',
            'primary_domain' => 'magangjogja.com',
            'badge_bg'       => 'bg-emerald-50',
            'badge_border'   => 'border-emerald-200',
            'badge_text'     => 'text-emerald-700',
        ],
        'daftar.magangku.com' => [
            'code'           => 'MK',
            'name'           => 'Magangku',
            'primary_domain' => 'magangku.com',
            'badge_bg'       => 'bg-cyan-50',
            'badge_border'   => 'border-cyan-200',
            'badge_text'     => 'text-cyan-700',
        ],
        'daftar.tuantanah.com' => [
            'code'           => 'TT',
            'name'           => 'Tuan Tanah',
            'primary_domain' => 'tuantanah.com',
            'badge_bg'       => 'bg-amber-50',
            'badge_border'   => 'border-amber-200',
            'badge_text'     => 'text-amber-700',
        ],
        'daftar.makeover.id' => [
            'code'           => 'MO',
            'name'           => 'Makeover',
            'primary_domain' => 'makeover.id',
            'badge_bg'       => 'bg-pink-50',
            'badge_border'   => 'border-pink-200',
            'badge_text'     => 'text-pink-700',
        ],
        'daftar.bimbelcerdas.com' => [
            'code'           => 'BC',
            'name'           => 'Bimbel Cerdas',
            'primary_domain' => 'bimbelcerdas.com',
            'badge_bg'       => 'bg-indigo-50',
            'badge_border'   => 'border-indigo-200',
            'badge_text'     => 'text-indigo-700',
        ],
        'daftar.areakerja.com' => [
            'code'           => 'AK',
            'name'           => 'Area Kerja',
            'primary_domain' => 'areakerja.com',
            'badge_bg'       => 'bg-sky-50',
            'badge_border'   => 'border-sky-200',
            'badge_text'     => 'text-sky-700',
        ],
        'daftar.tukanglas.org' => [
            'code'           => 'TL',
            'name'           => 'Tukang Las',
            'primary_domain' => 'tukanglas.org',
            'badge_bg'       => 'bg-orange-50',
            'badge_border'   => 'border-orange-200',
            'badge_text'     => 'text-orange-700',
        ],
        'daftar.titipsini.com' => [
            'code'           => 'TS',
            'name'           => 'Titip Sini',
            'primary_domain' => 'titipsini.com',
            'badge_bg'       => 'bg-amber-50',
            'badge_border'   => 'border-amber-200',
            'badge_text'     => 'text-amber-700',
        ],
        'daftar.seveninc.web.id' => [
            'code'           => 'SI',
            'name'           => 'SEVEN INC',
            'primary_domain' => 'seveninc.web.id',
            'badge_bg'       => 'bg-emerald-50',
            'badge_border'   => 'border-emerald-200',
            'badge_text'     => 'text-emerald-700',
        ],
        'seveninc.web.id' => [
            'code'           => 'SI',
            'name'           => 'SEVEN INC',
            'primary_domain' => 'seveninc.web.id',
            'badge_bg'       => 'bg-emerald-50',
            'badge_border'   => 'border-emerald-200',
            'badge_text'     => 'text-emerald-700',
        ],
    ],
];
