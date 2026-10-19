<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudyProgramSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $prodis = [
            'Informatika','Sistem Informasi','Teknik Industri','Teknik Elektro','Teknik Mesin',
            'Manajemen','Akuntansi','Ilmu Komunikasi','Desain Komunikasi Visual','Desain Produk',
            'Agribisnis','Teknik Sipil','Teknik Arsitektur','Teknologi Informasi','Ilmu Hukum'
        ];
        $facultyIds = DB::table('faculties')->pluck('id')->toArray();
        
        foreach ($facultyIds as $facId) {
            $randomProdis = collect($prodis)->random(5);
            foreach ($randomProdis as $prodi) {
                DB::table('study_programs')->updateOrInsert(
                    [
                        'faculty_id' => $facId,
                        'name' => $prodi
                    ],
                    [
                        'faculty_id' => $facId,
                        'name' => $prodi
                    ]
                );
            }
        }
    }
}
