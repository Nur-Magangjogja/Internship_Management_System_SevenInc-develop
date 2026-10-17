<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FacultySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faculties = ['Teknik','Ilmu Komputer','Ekonomi & Bisnis','Ilmu Sosial & Politik','Seni & Desain','Kedokteran','Pertanian','Vokasi'];
        $institutionIds = DB::table('institutions')->pluck('id')->toArray();
        
        foreach ($institutionIds as $instId) {
            foreach ($faculties as $fac) {
                DB::table('faculties')->updateOrInsert(
                    [
                        'institution_id' => $instId,
                        'name' => $fac
                    ],
                    [
                        'institution_id' => $instId,
                        'name' => $fac
                    ]
                );
            }
        }
    }
}
