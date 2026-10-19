<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Rename existing legacy divisions in-place to preserve foreign key IDs (1-18)
        $idMap = [
            1  => ['name' => 'Administrasi', 'code' => 'ADM'],
            2  => ['name' => 'Human Resource', 'code' => 'HRD'],
            3  => ['name' => 'UI/UX Designer', 'code' => 'UIUX'],
            4  => ['name' => 'Programmer', 'code' => 'PROG'],
            5  => ['name' => 'Fotografer', 'code' => 'FOTO'],
            6  => ['name' => 'Videografer', 'code' => 'VIDEO'],
            7  => ['name' => 'Desain Grafis', 'code' => 'GD'],
            8  => ['name' => 'Social Media Specialist', 'code' => 'SOCMED'],
            9  => ['name' => 'Content Writer', 'code' => 'CW'],
            10 => ['name' => 'Content Planner', 'code' => 'CP'],
            11 => ['name' => 'Marketing / Sales', 'code' => 'SALES'],
            12 => ['name' => 'Marcom/Public Relation', 'code' => 'PR'],
            13 => ['name' => 'Digital Marketing', 'code' => 'DM'],
            14 => ['name' => 'Tiktok Creator', 'code' => 'TIKTOK'],
            15 => ['name' => 'Project Manager', 'code' => 'PM'],
            16 => ['name' => 'Las', 'code' => 'LAS'],
            17 => ['name' => 'Animasi', 'code' => 'ANIM'],
            18 => ['name' => 'Customer Service', 'code' => 'CS'],
        ];

        $allDivisions = array_merge(array_values($idMap), [
            ['name' => 'Host/Presenter', 'code' => 'HOST'],
            ['name' => 'Voice Over Talent', 'code' => 'VO'],
            ['name' => 'Research & Development', 'code' => 'RND'],
        ]);

        $protectedNames = ['Host/Presenter', 'Voice Over Talent', 'Research & Development'];

        foreach ($idMap as $id => $data) {
            DB::table('divisions')
                ->where('id', $id)
                ->whereNotIn('name', $protectedNames)
                ->update([
                    'name'      => $data['name'],
                    'slug'      => Str::slug($data['name'], '_'),
                    'code'      => $data['code'],
                    'is_active' => true,
                ]);
        }

        // 2. Insert or update all 21 standard divisions by name so none are ever missing
        foreach ($allDivisions as $div) {
            DB::table('divisions')->updateOrInsert(
                ['name' => $div['name']],
                [
                    'slug'      => Str::slug($div['name'], '_'),
                    'code'      => $div['code'],
                    'is_active' => true,
                ]
            );
        }
    }
}
