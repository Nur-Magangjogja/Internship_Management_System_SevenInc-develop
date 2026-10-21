<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

class DocumentTemplateSeeder extends Seeder
{
    /**
     * Seed default document templates for LOA, SKL, Certificate, Assessment, Recommendation.
     */
    public function run(): void
    {
        DocumentTemplate::seedStandardDefaultTemplates();
    }
}
