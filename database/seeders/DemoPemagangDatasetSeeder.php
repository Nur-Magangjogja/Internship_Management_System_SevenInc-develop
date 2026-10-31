<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoPemagangDatasetSeeder extends Seeder
{
    /**
     * Seed dataset pemagang dummy representatif untuk keperluan demo dan testing lokal.
     * Dipisahkan dari DatabaseSeeder utama agar instalasi baru/produksi
     * bersih tanpa data tiruan pemagang.
     */
    public function run(): void
    {
        $this->call([
            DummyPemagangSeeder::class,
            InternshipRegistrationSeeder::class,
        ]);
    }
}
