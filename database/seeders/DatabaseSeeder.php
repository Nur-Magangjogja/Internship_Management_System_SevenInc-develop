<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Berfungsi sebagai baseline seeder awal proyek. Data master sistem,
     * template dokumen, dan 11 Brand Resmi dasar dijalankan secara default
     * (dengan profil isian operasional bersih/kosong untuk diisi Admin).
     * Data pemagang dummy dinonaktifkan (komentar) agar database bersih.
     */
    public function run(): void
    {
        $this->call([
            // 1. Data Master Dasar & Infrastruktur Sistem (Wajib / Baseline Proyek)
            CitySeeder::class,
            InstitutionSeeder::class,
            FacultySeeder::class,
            StudyProgramSeeder::class,
            DivisionSeeder::class,
            AdminUserSeeder::class,
            SpreadsheetConnectionSeeder::class,
            Spreadsheet2026SchemaSeeder::class,
            SpreadsheetFollowUpSchemaSeeder::class,
            FormFieldSeeder::class,
            DocumentTemplateSeeder::class,

            // 2. Data Brand Resmi Dasar (11 Brand terdaftar dengan Nama & Kode murni, isian operasional Draft)
            BrandSeeder::class,

            // 3. Dataset Opsional untuk Demo/Testing (Dinonaktifkan pada Instalasi Bersih)
            // OfficialBrandsDatasetSeeder::class, // Full legacy dataset dengan domain/alamat statis
            // DemoPemagangDatasetSeeder::class,   // Data pemagang tiruan dummy
        ]);
    }
}
