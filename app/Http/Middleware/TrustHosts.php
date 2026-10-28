<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        return [
            $this->allSubdomainsOfApplicationUrl(),
            'localhost',
            '127.0.0.1',
            'daftar.republikweb.net',
            'daftar.adakamar.id',
            'daftar.magangjogja.com',
            'daftar.magangku.com',
            'daftar.tuantanah.com',
            'daftar.makeover.id',
            'daftar.bimbelcerdas.com',
            'daftar.areakerja.com',
            'daftar.tukanglas.org',
            'daftar.titipsini.com',
            'daftar.seveninc.web.id',
            'seveninc.web.id',
        ];
    }
}
