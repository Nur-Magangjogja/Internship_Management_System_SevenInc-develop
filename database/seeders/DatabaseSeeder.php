<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BrandSeeder::class,
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
            DummyPemagangSeeder::class,
            InternshipRegistrationSeeder::class,
        ]);
    }
}
