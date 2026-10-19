<?php

namespace App\Services\MailMerge;

use App\Models\SystemAsset;

class VariableRegistry
{
    /**
     * Dapatkan metadata seluruh variabel yang terdaftar (Data & Aset).
     */
    public static function getDefinitions(): array
    {
        return [
            // ==================== VARIABEL DATA: PEMAGANG ====================
            'pemagang.nama' => [
                'token'       => 'pemagang.nama',
                'aliases'     => ['intern_name', 'fullname', 'nama_siswa', 'nama_pemagang'],
                'label'       => 'Nama Lengkap Pemagang',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => 'Ahmad Zaki Fauzi',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'internship_registrations.fullname',
            ],
            'pemagang.nim' => [
                'token'       => 'pemagang.nim',
                'aliases'     => ['student_id', 'nim_nis', 'nim', 'nis'],
                'label'       => 'Nomor Induk Mahasiswa / Siswa (NIM / NISN)',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => '2105101045',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'internship_registrations.student_id',
            ],
            'pemagang.institusi' => [
                'token'       => 'pemagang.institusi',
                'aliases'     => ['institution_name', 'instansi', 'sekolah', 'universitas'],
                'label'       => 'Nama Kampus / Sekolah Pemagang',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => 'Universitas Gadjah Mada',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'internship_registrations.institution_name',
            ],
            'pemagang.prodi' => [
                'token'       => 'pemagang.prodi',
                'aliases'     => ['study_program', 'jurusan', 'program_studi'],
                'label'       => 'Program Studi / Jurusan',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => 'Teknologi Informasi',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'internship_registrations.study_program',
            ],
            'pemagang.divisi' => [
                'token'       => 'pemagang.divisi',
                'aliases'     => ['division_name', 'divisi', 'departemen'],
                'label'       => 'Divisi / Posisi Magang',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => 'Fullstack Web Development',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'divisions.name',
            ],
            'pemagang.brand' => [
                'token'       => 'pemagang.brand',
                'aliases'     => ['brand_name', 'company_name', 'unit_bisnis'],
                'label'       => 'Nama Brand Penempatan',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => 'Seven Inc',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'brands.name',
            ],
            'pemagang.tanggal_mulai' => [
                'token'       => 'pemagang.tanggal_mulai',
                'aliases'     => ['start_date', 'tgl_mulai'],
                'label'       => 'Tanggal Mulai Magang',
                'category'    => 'Data Pemagang',
                'type'        => 'date',
                'sample'      => '01 Februari 2026',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'internship_registrations.start_date',
            ],
            'pemagang.tanggal_selesai' => [
                'token'       => 'pemagang.tanggal_selesai',
                'aliases'     => ['end_date', 'tgl_selesai'],
                'label'       => 'Tanggal Berakhir Magang',
                'category'    => 'Data Pemagang',
                'type'        => 'date',
                'sample'      => '31 Juli 2026',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'internship_registrations.end_date',
            ],
            'pemagang.periode' => [
                'token'       => 'pemagang.periode',
                'aliases'     => ['internship_period', 'periode'],
                'label'       => 'Rentang Periode Magang Lengkap',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => '01 Februari 2026 - 31 Juli 2026',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'computed_period',
            ],
            'pemagang.kontak' => [
                'token'       => 'pemagang.kontak',
                'aliases'     => ['phone_number', 'kontak', 'no_hp'],
                'label'       => 'Nomor Kontak / WhatsApp Pemagang',
                'category'    => 'Data Pemagang',
                'type'        => 'text',
                'sample'      => '081234567890',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'internship_registrations.phone_number',
            ],

            // ==================== VARIABEL DATA: DOKUMEN ====================
            'dokumen.nomor' => [
                'token'       => 'dokumen.nomor',
                'aliases'     => ['company_mou_number', 'loa_number', 'skl_number', 'certificate_number'],
                'label'       => 'Nomor Resmi Dokumen',
                'category'    => 'Data Dokumen',
                'type'        => 'text',
                'sample'      => '045/LOA/DEV/SEVEN.SI/II/2026',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'generated_document.document_number',
            ],
            'dokumen.tanggal' => [
                'token'       => 'dokumen.tanggal',
                'aliases'     => ['agreement_date', 'issue_date', 'published_at'],
                'label'       => 'Tanggal Penerbitan Dokumen',
                'category'    => 'Data Dokumen',
                'type'        => 'date',
                'sample'      => '19 Oktober 2026',
                'scope'       => ['admin', 'hr_staff', 'pemagang'],
                'source'      => 'generated_document.published_at',
            ],
            'dokumen.penandatangan_nama' => [
                'token'       => 'dokumen.penandatangan_nama',
                'aliases'     => ['signatory_name', 'company_signatory_name'],
                'label'       => 'Nama Pejabat Penandatangan Perusahaan',
                'category'    => 'Data Dokumen',
                'type'        => 'text',
                'sample'      => 'Rekario Danny Sanjaya, S.Kom.',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'brand.signatory_name',
            ],
            'dokumen.penandatangan_jabatan' => [
                'token'       => 'dokumen.penandatangan_jabatan',
                'aliases'     => ['signatory_position', 'company_signatory_title'],
                'label'       => 'Jabatan Pejabat Penandatangan',
                'category'    => 'Data Dokumen',
                'type'        => 'text',
                'sample'      => 'Direktur Utama',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'brand.signatory_position',
            ],
            'perusahaan.nama' => [
                'token'       => 'perusahaan.nama',
                'aliases'     => ['company_name', 'nama_perusahaan', 'second_party_company_name'],
                'label'       => 'Nama Perusahaan Pihak Kedua',
                'category'    => 'Data Dokumen',
                'type'        => 'text',
                'sample'      => 'SEVEN INC',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'company_name',
            ],

            // ==================== VARIABEL DATA: INSTITUSI ====================
            'institusi.nama' => [
                'token'       => 'institusi.nama',
                'aliases'     => ['institution_name'],
                'label'       => 'Nama Institusi Mitra',
                'category'    => 'Kemitraan Institusi',
                'type'        => 'text',
                'sample'      => 'SMK Negeri 2 Yogyakarta',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'institutions.name',
            ],
            'institusi.alamat' => [
                'token'       => 'institusi.alamat',
                'aliases'     => ['institution_address'],
                'label'       => 'Alamat Lengkap Institusi Mitra',
                'category'    => 'Kemitraan Institusi',
                'type'        => 'text',
                'sample'      => 'Jl. AM Sangaji No. 47, Jetis, Yogyakarta',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'institutions.address',
            ],
            'institusi.kontak_nama' => [
                'token'       => 'institusi.kontak_nama',
                'aliases'     => ['institution_contact_name', 'institution_signatory_name'],
                'label'       => 'Nama Kontak / Kepala / Pembimbing Institusi',
                'category'    => 'Kemitraan Institusi',
                'type'        => 'text',
                'sample'      => 'Drs. Supriyanto, M.Pd.',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'institution_contacts.name',
            ],
            'institusi.kontak_jabatan' => [
                'token'       => 'institusi.kontak_jabatan',
                'aliases'     => ['institution_signatory_title'],
                'label'       => 'Jabatan Kontak / Penandatangan Institusi',
                'category'    => 'Kemitraan Institusi',
                'type'        => 'text',
                'sample'      => 'Kepala Sekolah',
                'scope'       => ['admin', 'hr_staff'],
                'source'      => 'institution_contacts.position',
            ],

            // ==================== VARIABEL ASET VISUAL ====================
            'asset.company.logo' => [
                'token'       => 'asset.company.logo',
                'aliases'     => ['company_logo', 'logo_perusahaan', 'logo_seveninc'],
                'label'       => 'Logo Resmi Perusahaan (Seven Inc)',
                'category'    => 'Aset Visual',
                'type'        => 'image',
                'sample'      => '/images/logos/logo_seveninc.png',
                'scope'       => ['admin', 'hr_staff'],
                'asset_type'  => SystemAsset::CATEGORY_LOGO,
            ],
            'asset.brand.logo' => [
                'token'       => 'asset.brand.logo',
                'aliases'     => ['brand_logo', 'logo_brand'],
                'label'       => 'Logo Brand Terkait',
                'category'    => 'Aset Visual',
                'type'        => 'image',
                'sample'      => '/images/brands/brand_logo.png',
                'scope'       => ['admin', 'hr_staff'],
                'asset_type'  => SystemAsset::CATEGORY_LOGO,
            ],
            'asset.letterhead' => [
                'token'       => 'asset.letterhead',
                'aliases'     => ['letterhead', 'kop_surat'],
                'label'       => 'Kop Surat Resmi',
                'category'    => 'Aset Visual',
                'type'        => 'image',
                'sample'      => '/images/letterheads/letterhead.png',
                'scope'       => ['admin', 'hr_staff'],
                'asset_type'  => SystemAsset::CATEGORY_LETTERHEAD,
            ],
            'asset.signature' => [
                'token'       => 'asset.signature',
                'aliases'     => ['signature', 'tanda_tangan', 'ttd_pejabat'],
                'label'       => 'Tanda Tangan Pejabat Pengesah',
                'category'    => 'Aset Visual',
                'type'        => 'image',
                'sample'      => '/images/signatures/ttd.png',
                'scope'       => ['admin', 'hr_staff'],
                'asset_type'  => SystemAsset::CATEGORY_SIGNATURE,
            ],
            'asset.stamp' => [
                'token'       => 'asset.stamp',
                'aliases'     => ['stamp', 'stempel', 'stempel_perusahaan'],
                'label'       => 'Stempel Basah Perusahaan',
                'category'    => 'Aset Visual',
                'type'        => 'image',
                'sample'      => '/images/stamps/stamp.png',
                'scope'       => ['admin', 'hr_staff'],
                'asset_type'  => SystemAsset::CATEGORY_STAMP,
            ],
            'asset.background' => [
                'token'       => 'asset.background',
                'aliases'     => ['background', 'certificate_bg', 'latar_belakang'],
                'label'       => 'Background / Watermark Sertifikat',
                'category'    => 'Aset Visual',
                'type'        => 'image',
                'sample'      => '/images/backgrounds/cert_bg.png',
                'scope'       => ['admin', 'hr_staff'],
                'asset_type'  => SystemAsset::CATEGORY_BACKGROUND,
            ],
        ];
    }

    /**
     * Dapatkan daftar variabel yang dikelompokkan berdasarkan kategori untuk tampilan UI editor.
     */
    public static function getGroupedForEditor(): array
    {
        $definitions = static::getDefinitions();
        $grouped = [];

        foreach ($definitions as $item) {
            $cat = $item['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = [
                'name'        => $item['label'],
                'token'       => '{{' . $item['token'] . '}}',
                'bracket'     => '[' . ($item['aliases'][0] ?? $item['token']) . ']',
                'type'        => $item['type'],
                'sample'      => $item['sample'],
                'description' => "Sumber: {$item['source']}",
            ];
        }

        return $grouped;
    }
}
