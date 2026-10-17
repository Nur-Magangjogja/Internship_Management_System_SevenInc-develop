<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cities = ['Jakarta','Bandung','Bekasi','Depok','Tangerang','Bogor','Cimahi','Cirebon','Tasikmalaya','Semarang','Solo','Yogyakarta','Magelang','Surabaya','Sidoarjo','Gresik','Malang','Kediri','Jember','Banyuwangi','Denpasar','Mataram','Medan','Padang','Pekanbaru','Palembang','Lampung','Pontianak','Banjarmasin','Makassar', 'Sleman', 'Bantul', 'Kulonprogo'];
        foreach ($cities as $city) {
            DB::table('cities')->updateOrInsert(['name' => $city], ['name' => $city]);
        }
    }
}
