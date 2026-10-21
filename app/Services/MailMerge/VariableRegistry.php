<?php

namespace App\Services\MailMerge;

use App\Models\SystemAsset;

class VariableRegistry
{
    // ==================== CANONICAL DOCUMENT TYPES ====================
    public const DOC_TYPE_MOU            = 'mou';
    public const DOC_TYPE_LOA            = 'loa';
    public const DOC_TYPE_SKL            = 'skl';
    public const DOC_TYPE_CERTIFICATE    = 'certificate';
    public const DOC_TYPE_ASSESSMENT     = 'assessment';
    public const DOC_TYPE_RECOMMENDATION = 'recommendation';

    public const ALL_DOC_TYPES = [
        self::DOC_TYPE_MOU,
        self::DOC_TYPE_LOA,
        self::DOC_TYPE_SKL,
        self::DOC_TYPE_CERTIFICATE,
        self::DOC_TYPE_ASSESSMENT,
        self::DOC_TYPE_RECOMMENDATION,
    ];

    // ==================== DATA TYPES ====================
    public const TYPE_STRING     = 'string';
    public const TYPE_DATE       = 'date';
    public const TYPE_NUMBER     = 'number';
    public const TYPE_CURRENCY   = 'currency';
    public const TYPE_IMAGE      = 'image';
    public const TYPE_TABLE      = 'table';
    public const TYPE_RICH_TEXT  = 'rich_text';
    public const TYPE_PAGE_FIELD = 'page_field';

    // ==================== REQUIRED POLICIES ====================
    public const POLICY_REQUIRED      = 'required';
    public const POLICY_OPTIONAL      = 'optional';
    public const POLICY_REQUIRED_WHEN = 'required_when';

    // ==================== OWNER POLICIES ====================
    public const OWNER_GLOBAL      = 'global';
    public const OWNER_BRAND       = 'brand';
    public const OWNER_INSTITUTION = 'institution';
    public const OWNER_INTERN      = 'intern';
    public const OWNER_SYSTEM      = 'system';

    // ==================== NULL POLICIES ====================
    public const NULL_POLICY_EMPTY      = 'empty';
    public const NULL_POLICY_DASH       = 'dash';
    public const NULL_POLICY_HIDE_BLOCK = 'hide_block';

    /**
     * Dapatkan metadata seluruh variabel yang terdaftar (Data & Aset) untuk seluruh 6 tipe dokumen.
     * Single Source of Truth bagi seluruh editor, validasi, dan rendering dokumen.
     */
    public static function getDefinitions(): array
    {
        return [
            // =========================================================================
            // 1. DATA PEMAGANG (LOA, SKL, CERTIFICATE, ASSESSMENT, RECOMMENDATION)
            // =========================================================================
            'pemagang.nama' => [
                'token'                  => 'pemagang.nama',
                'aliases'                => ['student_name', 'intern_name', 'fullname', 'nama_siswa', 'nama_pemagang', 'nama', 'peserta.nama', 'participant_name', 'attendee_name', 'nama_peserta'],
                'label'                  => 'Nama Lengkap Pemagang / Peserta',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Ahmad Zaki Fauzi',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'internship_registrations.fullname',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.nim' => [
                'token'                  => 'pemagang.nim',
                'aliases'                => ['student_id', 'nim_nis', 'nim', 'nis', 'nomor_induk'],
                'label'                  => 'Nomor Induk Mahasiswa / Siswa (NIM / NISN)',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '2105101045',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'internship_registrations.student_id',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.institusi' => [
                'token'                  => 'pemagang.institusi',
                'aliases'                => ['institution_name', 'instansi', 'sekolah', 'universitas', 'kampus_asal'],
                'label'                  => 'Nama Kampus / Sekolah Asal Pemagang',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Universitas Gadjah Mada',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'internship_registrations.institution_name',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.prodi' => [
                'token'                  => 'pemagang.prodi',
                'aliases'                => ['study_program', 'jurusan', 'program_studi', 'major'],
                'label'                  => 'Program Studi / Jurusan',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Teknologi Informasi',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'internship_registrations.study_program',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.divisi' => [
                'token'                  => 'pemagang.divisi',
                'aliases'                => ['division_name', 'divisi', 'departemen', 'magang.divisi', 'posisi_magang'],
                'label'                  => 'Divisi / Posisi Magang',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Fullstack Web Development',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'divisions.name',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.brand' => [
                'token'                  => 'pemagang.brand',
                'aliases'                => ['brand_name', 'unit_bisnis', 'brand_pemagang', 'penempatan_brand'],
                'label'                  => 'Nama Brand Penempatan',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Seven Inc',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'brands.name',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.tanggal_mulai' => [
                'token'                  => 'pemagang.tanggal_mulai',
                'aliases'                => ['start_date', 'tgl_mulai', 'magang.tanggal_mulai', 'tanggal_mulai'],
                'label'                  => 'Tanggal Mulai Magang',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_DATE,
                'type'                   => 'date',
                'sample'                 => '01 Februari 2026',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'internship_registrations.start_date',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.tanggal_selesai' => [
                'token'                  => 'pemagang.tanggal_selesai',
                'aliases'                => ['end_date', 'tgl_selesai', 'magang.tanggal_selesai', 'tanggal_selesai'],
                'label'                  => 'Tanggal Berakhir Magang',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_DATE,
                'type'                   => 'date',
                'sample'                 => '31 Juli 2026',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'internship_registrations.end_date',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.periode' => [
                'token'                  => 'pemagang.periode',
                'aliases'                => ['internship_period', 'periode', 'rentang_periode'],
                'label'                  => 'Rentang Periode Magang Lengkap',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '01 Februari 2026 - 31 Juli 2026',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'computed_period',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.durasi' => [
                'token'                  => 'pemagang.durasi',
                'aliases'                => ['magang.durasi', 'durasi', 'duration', 'durasi_magang'],
                'label'                  => 'Durasi Periode Magang',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '6 (enam) bulan',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'computed_duration',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'pemagang.kontak' => [
                'token'                  => 'pemagang.kontak',
                'aliases'                => ['phone_number', 'kontak', 'no_hp', 'telepon_pemagang'],
                'label'                  => 'Nomor Kontak / WhatsApp Pemagang',
                'category'               => 'Data Pemagang',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '081234567890',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'internship_registrations.phone_number',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],

            // =========================================================================
            // 2. DATA PIHAK BRAND / BADAN HUKUM PERUSAHAAN (SELURUH DOKUMEN)
            // =========================================================================
            'brand.nama' => [
                'token'                  => 'brand.nama',
                'aliases'                => ['perusahaan.nama', 'company_name', 'nama_perusahaan', 'second_party_company_name', 'badan_hukum.nama'],
                'label'                  => 'Nama Brand / Entitas Penyelenggara',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'SEVEN INC',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'brands.name',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.alamat' => [
                'token'                  => 'brand.alamat',
                'aliases'                => ['perusahaan.alamat', 'company_address', 'alamat_perusahaan', 'second_party_address'],
                'label'                  => 'Alamat Kantor Brand / Perusahaan',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'brands.address',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.telepon' => [
                'token'                  => 'brand.telepon',
                'aliases'                => ['perusahaan.telepon', 'company_phone', 'telepon_perusahaan', 'second_party_phone'],
                'label'                  => 'Nomor Telepon Brand / Perusahaan',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '0274-4534571',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU, self::DOC_TYPE_LOA, self::DOC_TYPE_SKL],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'brands.phone',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.email' => [
                'token'                  => 'brand.email',
                'aliases'                => ['perusahaan.email', 'company_email', 'email_perusahaan', 'second_party_email'],
                'label'                  => 'Email Resmi Brand / Perusahaan',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'info@seveninc.co.id',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU, self::DOC_TYPE_LOA, self::DOC_TYPE_SKL],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'brands.email',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.fax' => [
                'token'                  => 'brand.fax',
                'aliases'                => ['perusahaan.fax', 'company_fax'],
                'label'                  => 'Nomor Fax Perusahaan',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '-',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'brands.fax',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.kota' => [
                'token'                  => 'brand.kota',
                'aliases'                => ['perusahaan.kota', 'dokumen.tempat', 'agreement_place', 'tempat_terbit', 'kota_terbit'],
                'label'                  => 'Kota Domisili / Penerbitan Perusahaan',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Yogyakarta',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'agreement_place',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.kodepos' => [
                'token'                  => 'brand.kodepos',
                'aliases'                => ['perusahaan.kodepos', 'kodepos_perusahaan'],
                'label'                  => 'Kode Pos Kantor Perusahaan',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '55198',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU, self::DOC_TYPE_LOA],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'brands.postal_code',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.penandatangan.nama' => [
                'token'                  => 'brand.penandatangan.nama',
                'aliases'                => ['dokumen.penandatangan_nama', 'perusahaan.pimpinan', 'company_signatory_name', 'company_leader', 'second_party_signatory_name', 'pimpinan', 'signatory_name'],
                'label'                  => 'Nama Pejabat Penandatangan Perusahaan',
                'category'               => 'Pejabat Penandatangan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Rekario Danny Sanjaya, S.Kom.',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'brand.signatory_name',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.penandatangan.jabatan' => [
                'token'                  => 'brand.penandatangan.jabatan',
                'aliases'                => ['dokumen.penandatangan_jabatan', 'perusahaan.jabatan', 'company_signatory_title', 'leader_position', 'second_party_signatory_title', 'jabatan_pimpinan', 'signatory_position'],
                'label'                  => 'Jabatan Pejabat Penandatangan Perusahaan',
                'category'               => 'Pejabat Penandatangan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Direktur Utama',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'brand.signatory_position',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.unit_korespondensi' => [
                'token'                  => 'brand.unit_korespondensi',
                'aliases'                => ['company_correspondence_unit', 'unit_korespondensi_perusahaan'],
                'label'                  => 'Unit Korespondensi Perusahaan (u.p.)',
                'category'               => 'Identitas Brand & Perusahaan',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Human Resource Department',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'company_correspondence_unit',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'brand.nomor_mou' => [
                'token'                  => 'brand.nomor_mou',
                'aliases'                => ['company_mou_number', 'mou.nomor_brand', 'nomor_mou_perusahaan'],
                'label'                  => 'Nomor Surat Perjanjian Pihak Perusahaan',
                'category'               => 'Data Dokumen MOU',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '012/MOU/SEVEN.SI/V/2026',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'mous.company_mou_number',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],

            // =========================================================================
            // 3. DATA PIHAK INSTITUSI MITRA (MOU & DOKUMEN PEMAGANG)
            // =========================================================================
            'institusi.nama' => [
                'token'                  => 'institusi.nama',
                'aliases'                => ['institution_name', 'first_party_institution_name', 'nama_institusi', 'nama_kampus', 'nama_sekolah'],
                'label'                  => 'Nama Resmi Institusi Mitra',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Universitas Gadjah Mada',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'institutions.name',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.alamat' => [
                'token'                  => 'institusi.alamat',
                'aliases'                => ['institution_address', 'first_party_address', 'alamat_institusi'],
                'label'                  => 'Alamat Lengkap Institusi Mitra',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Bulaksumur, Caturtunggal, Depok, Sleman, D.I. Yogyakarta 55281',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU, self::DOC_TYPE_LOA],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'institutions.address',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.telepon' => [
                'token'                  => 'institusi.telepon',
                'aliases'                => ['institution_phone', 'first_party_phone', 'telepon_institusi'],
                'label'                  => 'Nomor Telepon Institusi Mitra',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '0274-588688',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'institutions.phone',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.fax' => [
                'token'                  => 'institusi.fax',
                'aliases'                => ['institution_fax', 'fax_institusi'],
                'label'                  => 'Nomor Fax Institusi Mitra',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '-',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'institutions.fax',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.email' => [
                'token'                  => 'institusi.email',
                'aliases'                => ['institution_email', 'first_party_email', 'email_institusi'],
                'label'                  => 'Email Resmi Institusi Mitra',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'kerjasama@ugm.ac.id',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'institutions.email',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.penandatangan.nama' => [
                'token'                  => 'institusi.penandatangan.nama',
                'aliases'                => ['institution_signatory_name', 'institusi.kontak_nama', 'institution_contact_name', 'first_party_signatory_name', 'pimpinan_institusi'],
                'label'                  => 'Nama Pejabat Penandatangan Institusi',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Prof. Dr. Ir. Suratman, M.Sc.',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'institution_contacts.name',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.penandatangan.jabatan' => [
                'token'                  => 'institusi.penandatangan.jabatan',
                'aliases'                => ['institution_signatory_title', 'institusi.kontak_jabatan', 'first_party_signatory_title', 'jabatan_penandatangan_institusi'],
                'label'                  => 'Jabatan Penandatangan Institusi (Rektor / Dekan / Kaprodi)',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Wakil Rektor Bidang Kerja Sama',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'institution_contacts.position_title',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.unit_korespondensi' => [
                'token'                  => 'institusi.unit_korespondensi',
                'aliases'                => ['institution_correspondence_unit', 'first_party_correspondence_unit', 'unit_korespondensi_institusi'],
                'label'                  => 'Unit Korespondensi Institusi (u.p.)',
                'category'               => 'Kemitraan Institusi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Direktorat Kemitraan dan Alumni',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'first_party_correspondence_unit',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'institusi.nomor_mou' => [
                'token'                  => 'institusi.nomor_mou',
                'aliases'                => ['institution_mou_number', 'mou.nomor_institusi', 'nomor_mou_institusi'],
                'label'                  => 'Nomor Surat Perjanjian Pihak Institusi',
                'category'               => 'Data Dokumen MOU',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '105/UN1/DIT-KMA/MOU/2026',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'mous.institution_mou_number',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],

            // =========================================================================
            // 4. DATA UMUM PENERBITAN & DOKUMEN (NOMOR, TANGGAL, HALAMAN)
            // =========================================================================
            'dokumen.nomor' => [
                'token'                  => 'dokumen.nomor',
                'aliases'                => ['loa_number', 'skl_number', 'certificate_number', 'nomor_dokumen', 'nomor_surat'],
                'label'                  => 'Nomor Resmi Penerbitan Dokumen',
                'category'               => 'Data Dokumen',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '045/LOA/DEV/SEVEN.SI/II/2026',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'generated_document.document_number',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'dokumen.tanggal' => [
                'token'                  => 'dokumen.tanggal',
                'aliases'                => ['agreement_date', 'issue_date', 'published_at', 'tanggal_terbit', 'tanggal_dokumen'],
                'label'                  => 'Tanggal Pengesahan / Penerbitan Dokumen',
                'category'               => 'Data Dokumen',
                'data_type'              => self::TYPE_DATE,
                'type'                   => 'date',
                'sample'                 => '19 Oktober 2026',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'generated_document.published_at',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'dokumen.tempat' => [
                'token'                  => 'dokumen.tempat',
                'aliases'                => ['agreement_place', 'tempat_terbit', 'kota_terbit', 'brand.kota', 'perusahaan.kota'],
                'label'                  => 'Tempat / Kota Penerbitan Dokumen',
                'category'               => 'Data Dokumen',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Yogyakarta',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'agreement_place',
                'source_owner_policy'    => self::OWNER_BRAND,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'dokumen.hari' => [
                'token'                  => 'dokumen.hari',
                'aliases'                => ['agreement_day', 'hari_terbit'],
                'label'                  => 'Nama Hari Penandatanganan (cth: Senin)',
                'category'               => 'Data Dokumen',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Senin',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'computed_day',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'dokumen.tanggal_terbilang' => [
                'token'                  => 'dokumen.tanggal_terbilang',
                'aliases'                => ['agreement_date_words', 'tgl_terbilang'],
                'label'                  => 'Kalimat Tanggal Terbilang Bahasa Indonesia',
                'category'               => 'Data Dokumen',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'sembilan belas bulan Oktober tahun dua ribu dua puluh enam',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'computed_date_words',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'dokumen.tanggal_singkat' => [
                'token'                  => 'dokumen.tanggal_singkat',
                'aliases'                => ['agreement_date_short', 'tgl_singkat'],
                'label'                  => 'Tanggal Singkat DD-MM-YYYY (cth: 19-10-2026)',
                'category'               => 'Data Dokumen',
                'data_type'              => self::TYPE_DATE,
                'type'                   => 'date',
                'sample'                 => '19-10-2026',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'computed_date_short',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'dokumen.halaman' => [
                'token'                  => 'dokumen.halaman',
                'aliases'                => ['page_number', 'current_page', 'nomor_halaman'],
                'label'                  => 'Nomor Halaman Aktif (Page Field)',
                'category'               => 'Tata Letak Halaman',
                'data_type'              => self::TYPE_PAGE_FIELD,
                'type'                   => 'text',
                'sample'                 => '1',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'page_renderer.current_page',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => false,
            ],
            'dokumen.total_halaman' => [
                'token'                  => 'dokumen.total_halaman',
                'aliases'                => ['total_pages', 'page_count', 'jumlah_halaman'],
                'label'                  => 'Total Jumlah Halaman Dokumen (Page Field)',
                'category'               => 'Tata Letak Halaman',
                'data_type'              => self::TYPE_PAGE_FIELD,
                'type'                   => 'text',
                'sample'                 => '3',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'page_renderer.total_pages',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => false,
            ],

            // =========================================================================
            // 5. DATA PERJANJIAN KHUSUS MOU
            // =========================================================================
            'mou.tanggal_mulai' => [
                'token'                  => 'mou.tanggal_mulai',
                'aliases'                => ['effective_start', 'tgl_mulai_mou'],
                'label'                  => 'Tanggal Mulai Berlaku Kerja Sama MOU',
                'category'               => 'Data Dokumen MOU',
                'data_type'              => self::TYPE_DATE,
                'type'                   => 'date',
                'sample'                 => '01 Januari 2026',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'mous.effective_start',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'mou.tanggal_selesai' => [
                'token'                  => 'mou.tanggal_selesai',
                'aliases'                => ['effective_end', 'tgl_selesai_mou'],
                'label'                  => 'Tanggal Berakhir Masa Berlaku Kerja Sama MOU',
                'category'               => 'Data Dokumen MOU',
                'data_type'              => self::TYPE_DATE,
                'type'                   => 'date',
                'sample'                 => '31 Desember 2028',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'mous.effective_end',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'mou.durasi' => [
                'token'                  => 'mou.durasi',
                'aliases'                => ['agreement_duration', 'masa_berlaku', 'mou.masa_berlaku', 'durasi_kerjasama'],
                'label'                  => 'Jangka Waktu Masa Berlaku MOU (cth: 3 (tiga) tahun)',
                'category'               => 'Data Dokumen MOU',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '3 (tiga) tahun',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'computed_mou_duration',
                'source_owner_policy'    => self::OWNER_SYSTEM,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => false,
                'can_override'           => true,
            ],

            // =========================================================================
            // 6. PENILAIAN & REKOMENDASI (ASSESSMENT & RECOMMENDATION)
            // =========================================================================
            'penilaian.nilai_akhir' => [
                'token'                  => 'penilaian.nilai_akhir',
                'aliases'                => ['nilai.angka', 'score_numeric', 'nilai_angka', 'nilai.rata_rata', 'rata_rata'],
                'label'                  => 'Nilai Rata-rata / Angka Akhir Penilaian',
                'category'               => 'Penilaian & Rekomendasi',
                'data_type'              => self::TYPE_NUMBER,
                'type'                   => 'text',
                'sample'                 => '92.5',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_REQUIRED_WHEN,
                'source'                 => 'intern_assessments.final_score',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'penilaian.nilai_huruf' => [
                'token'                  => 'penilaian.nilai_huruf',
                'aliases'                => ['nilai.huruf', 'score_grade', 'nilai_huruf'],
                'label'                  => 'Nilai Mutu / Grade Huruf (cth: A)',
                'category'               => 'Penilaian & Rekomendasi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'A',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'intern_assessments.grade_letter',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'penilaian.predikat' => [
                'token'                  => 'penilaian.predikat',
                'aliases'                => ['nilai.predikat', 'predicate', 'predikat', 'predikat_kelulusan'],
                'label'                  => 'Predikat Kelulusan (cth: Sangat Memuaskan)',
                'category'               => 'Penilaian & Rekomendasi',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Sangat Memuaskan',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'intern_assessments.predicate',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_DASH,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'penilaian.tabel_komponen' => [
                'token'                  => 'penilaian.tabel_komponen',
                'aliases'                => ['assessment_table', 'tabel_penilaian', 'komponen_nilai'],
                'label'                  => 'Tabel Dinamis Rincian Komponen Nilai',
                'category'               => 'Penilaian & Rekomendasi',
                'data_type'              => self::TYPE_TABLE,
                'type'                   => 'table',
                'sample'                 => null,
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_ASSESSMENT],
                'required_policy'        => self::POLICY_REQUIRED_WHEN,
                'source'                 => 'intern_assessment_scores',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => true,
                'can_override'           => false,
            ],
            'rekomendasi.catatan' => [
                'token'                  => 'rekomendasi.catatan',
                'aliases'                => ['recommendation_note', 'catatan_rekomendasi', 'rekomendasi.isi', 'isi_rekomendasi'],
                'label'                  => 'Isi Teks / Narasi Surat Rekomendasi',
                'category'               => 'Penilaian & Rekomendasi',
                'data_type'              => self::TYPE_RICH_TEXT,
                'type'                   => 'text',
                'sample'                 => 'Menunjukkan kompetensi teknis dan integritas profesional yang luar biasa selama bertugas.',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_REQUIRED,
                'source'                 => 'recommendations.notes',
                'source_owner_policy'    => self::OWNER_INTERN,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],

            // =========================================================================
            // 7. DATA KEGIATAN WEBINAR (SERTIFIKAT WEBINAR)
            // =========================================================================
            'webinar.judul' => [
                'token'                  => 'webinar.judul',
                'aliases'                => ['webinar_title', 'judul_webinar', 'title', 'nama_webinar'],
                'label'                  => 'Judul / Topik Kegiatan Webinar',
                'category'               => 'Kegiatan Webinar',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => 'Workshop UI/UX Design & Front-End Development Modern',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'webinars.title',
                'source_owner_policy'    => self::OWNER_GLOBAL,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'webinar.tanggal' => [
                'token'                  => 'webinar.tanggal',
                'aliases'                => ['webinar_date', 'tanggal_webinar', 'event_date', 'tanggal_pelaksanaan'],
                'label'                  => 'Tanggal Penyelenggaraan Webinar',
                'category'               => 'Kegiatan Webinar',
                'data_type'              => self::TYPE_STRING,
                'type'                   => 'text',
                'sample'                 => '25 Oktober 2026',
                'scope'                  => ['admin', 'hr_staff', 'pemagang'],
                'allowed_document_types' => [self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'webinars.event_date',
                'source_owner_policy'    => self::OWNER_GLOBAL,
                'null_policy'            => self::NULL_POLICY_EMPTY,
                'sensitive'              => false,
                'can_override'           => true,
            ],

            // =========================================================================
            // 8. ASET GRAFIS & TANDA TANGAN DUA PIHAK (OWNER ISOLATION STRICT)
            // =========================================================================
            'asset.brand.logo' => [
                'token'                  => 'asset.brand.logo',
                'aliases'                => ['asset.company.logo', 'company_logo', 'brand_logo', 'logo_perusahaan', 'logo_brand', 'logo_seveninc', 'asset.logo_perusahaan', 'asset.logo_brand'],
                'label'                  => 'Logo Resmi Brand / Perusahaan Penyelenggara',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/logos/logo_seveninc.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.brand',
                'source_owner_policy'    => self::OWNER_BRAND,
                'asset_type'             => SystemAsset::CATEGORY_LOGO,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'asset.company.logo' => [
                'token'                  => 'asset.company.logo',
                'aliases'                => ['asset.brand.logo', 'company_logo', 'brand_logo', 'logo_perusahaan', 'logo_brand', 'logo_seveninc', 'asset.logo_perusahaan', 'asset.logo_brand'],
                'label'                  => 'Logo Resmi Perusahaan (Seven Inc)',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/logos/logo_seveninc.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.brand',
                'source_owner_policy'    => self::OWNER_BRAND,
                'asset_type'             => SystemAsset::CATEGORY_LOGO,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'asset.institusi.logo' => [
                'token'                  => 'asset.institusi.logo',
                'aliases'                => ['asset.institution.logo', 'institution_logo', 'logo_institusi', 'logo_kampus', 'logo_sekolah', 'asset.logo_institusi'],
                'label'                  => 'Logo Resmi Institusi / Kampus / Sekolah Mitra',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/mou/institution-logo-placeholder.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU, self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.institution',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'asset_type'             => SystemAsset::CATEGORY_LOGO,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'asset.institution.logo' => [
                'token'                  => 'asset.institution.logo',
                'aliases'                => ['asset.institusi.logo', 'institution_logo', 'logo_institusi', 'logo_kampus', 'logo_sekolah', 'asset.logo_institusi'],
                'label'                  => 'Logo Resmi Institusi / Kampus / Sekolah Mitra',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/mou/institution-logo-placeholder.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU, self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.institution',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'asset_type'             => SystemAsset::CATEGORY_LOGO,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'asset.signature' => [
                'token'                  => 'asset.signature',
                'aliases'                => ['signature', 'tanda_tangan', 'ttd_pejabat', 'asset.brand.tanda_tangan', 'asset.company.signature', 'company_signature'],
                'label'                  => 'Tanda Tangan Pejabat Pengesah',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/signatures/ttd.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.signature.brand',
                'source_owner_policy'    => self::OWNER_BRAND,
                'asset_type'             => SystemAsset::CATEGORY_SIGNATURE,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'asset.stamp' => [
                'token'                  => 'asset.stamp',
                'aliases'                => ['stamp', 'stempel', 'stempel_perusahaan', 'asset.brand.stempel', 'asset.company.stamp', 'company_stamp'],
                'label'                  => 'Stempel Resmi Perusahaan',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/stamps/stamp.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.stamp.brand',
                'source_owner_policy'    => self::OWNER_BRAND,
                'asset_type'             => SystemAsset::CATEGORY_STAMP,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'asset.brand.tanda_tangan' => [
                'token'                  => 'asset.brand.tanda_tangan',
                'aliases'                => ['asset.signature', 'signature', 'tanda_tangan', 'ttd_pejabat', 'asset.company.signature', 'company_signature', 'asset.signature_perusahaan'],
                'label'                  => 'Tanda Tangan Pejabat Pengesah Perusahaan',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/signatures/ttd.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.signature.brand',
                'source_owner_policy'    => self::OWNER_BRAND,
                'asset_type'             => SystemAsset::CATEGORY_SIGNATURE,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'asset.institusi.tanda_tangan' => [
                'token'                  => 'asset.institusi.tanda_tangan',
                'aliases'                => ['asset.institution.signature', 'institution_signature', 'tanda_tangan_institusi', 'asset.signature_institusi'],
                'label'                  => 'Tanda Tangan Pejabat Institusi Mitra',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => null,
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.signature.institution',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'asset_type'             => SystemAsset::CATEGORY_SIGNATURE,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'asset.brand.stempel' => [
                'token'                  => 'asset.brand.stempel',
                'aliases'                => ['asset.stamp', 'stamp', 'stempel', 'stempel_perusahaan', 'asset.company.stamp', 'company_stamp', 'asset.stempel_perusahaan'],
                'label'                  => 'Stempel Resmi Brand / Perusahaan',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/stamps/stamp.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => self::ALL_DOC_TYPES,
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.stamp.brand',
                'source_owner_policy'    => self::OWNER_BRAND,
                'asset_type'             => SystemAsset::CATEGORY_STAMP,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'asset.institusi.stempel' => [
                'token'                  => 'asset.institusi.stempel',
                'aliases'                => ['asset.institution.stamp', 'institution_stamp', 'stempel_institusi', 'asset.stempel_institusi'],
                'label'                  => 'Stempel Resmi Institusi Mitra',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => null,
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.stamp.institution',
                'source_owner_policy'    => self::OWNER_INSTITUTION,
                'asset_type'             => SystemAsset::CATEGORY_STAMP,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => true,
                'can_override'           => true,
            ],
            'asset.letterhead' => [
                'token'                  => 'asset.letterhead',
                'aliases'                => ['letterhead', 'kop_surat', 'asset.kop_surat'],
                'label'                  => 'Kop Surat Resmi Brand / Perusahaan',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/letterheads/letterhead.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_MOU, self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.letterhead',
                'source_owner_policy'    => self::OWNER_BRAND,
                'asset_type'             => SystemAsset::CATEGORY_LETTERHEAD,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => false,
                'can_override'           => true,
            ],
            'asset.background' => [
                'token'                  => 'asset.background',
                'aliases'                => ['background', 'certificate_bg', 'latar_belakang', 'asset.latar_belakang'],
                'label'                  => 'Background / Bingkai Visual Sertifikat',
                'category'               => 'Aset Grafis & Visual',
                'data_type'              => self::TYPE_IMAGE,
                'type'                   => 'image',
                'sample'                 => '/images/backgrounds/cert_bg.png',
                'scope'                  => ['admin', 'hr_staff'],
                'allowed_document_types' => [self::DOC_TYPE_CERTIFICATE],
                'required_policy'        => self::POLICY_OPTIONAL,
                'source'                 => 'system_assets.background',
                'source_owner_policy'    => self::OWNER_GLOBAL,
                'asset_type'             => SystemAsset::CATEGORY_BACKGROUND,
                'null_policy'            => self::NULL_POLICY_HIDE_BLOCK,
                'sensitive'              => false,
                'can_override'           => true,
            ],
        ];
    }

    /**
     * Peta terbalik dari seluruh alias (termasuk placeholder legacy) ke token kanonis.
     * Mendukung pemilahan konteks jenis dokumen (V01) untuk menangani alias yang bersinggungan.
     */
    public static function getAliasesMap(?string $documentType = null): array
    {
        $docType = $documentType ? strtolower(trim($documentType)) : null;
        $docType = match ($docType) {
            'sertifikat'  => self::DOC_TYPE_CERTIFICATE,
            'penilaian'   => self::DOC_TYPE_ASSESSMENT,
            'rekomendasi' => self::DOC_TYPE_RECOMMENDATION,
            default       => $docType,
        };

        $map = [];
        foreach (static::getDefinitions() as $canonicalKey => $def) {
            $lowerKey = strtolower($canonicalKey);
            if (!isset($map[$lowerKey])) {
                $map[$lowerKey] = $canonicalKey;
            }
            foreach ($def['aliases'] ?? [] as $alias) {
                $lowerAlias = strtolower($alias);
                if (!isset($map[$lowerAlias])) {
                    $map[$lowerAlias] = $canonicalKey;
                }
            }
        }

        // Resolusi Ambigu Berdasarkan Konteks Dokumen (V01)
        if ($docType === self::DOC_TYPE_MOU) {
            $map['institution_name']           = 'institusi.nama';
            $map['institution_address']        = 'institusi.alamat';
            $map['institution_phone']          = 'institusi.telepon';
            $map['institution_fax']            = 'institusi.fax';
            $map['institution_email']          = 'institusi.email';
            $map['institution_signatory_name'] = 'institusi.penandatangan.nama';
            $map['institution_signatory_title']= 'institusi.penandatangan.jabatan';
            $map['institution_logo']           = 'asset.institusi.logo';
            $map['asset.institution.logo']     = 'asset.institusi.logo';
            $map['company_name']               = 'brand.nama';
            $map['perusahaan.nama']            = 'brand.nama';
            $map['company_address']            = 'brand.alamat';
            $map['company_phone']              = 'brand.telepon';
            $map['company_email']              = 'brand.email';
            $map['company_fax']                = 'brand.fax';
            $map['company_signatory_name']     = 'brand.penandatangan.nama';
            $map['company_signatory_title']    = 'brand.penandatangan.jabatan';
            $map['signatory_name']             = 'brand.penandatangan.nama';
            $map['signatory_position']         = 'brand.penandatangan.jabatan';
            $map['company_logo']               = 'asset.brand.logo';
            $map['asset.company.logo']         = 'asset.brand.logo';
            $map['start_date']                 = 'mou.tanggal_mulai';
            $map['tgl_mulai']                  = 'mou.tanggal_mulai';
            $map['end_date']                   = 'mou.tanggal_selesai';
            $map['tgl_selesai']                = 'mou.tanggal_selesai';
            $map['duration']                   = 'mou.durasi';
            $map['durasi']                     = 'mou.durasi';
        } elseif ($docType !== null && in_array($docType, [self::DOC_TYPE_LOA, self::DOC_TYPE_SKL, self::DOC_TYPE_CERTIFICATE, self::DOC_TYPE_ASSESSMENT, self::DOC_TYPE_RECOMMENDATION], true)) {
            $map['institution_name']           = 'pemagang.institusi';
            $map['company_name']               = 'brand.nama';
            $map['perusahaan.nama']            = 'brand.nama';
            $map['division_name']              = 'pemagang.divisi';
            $map['divisi']                     = 'pemagang.divisi';
            $map['signatory_name']             = 'brand.penandatangan.nama';
            $map['signatory_position']         = 'brand.penandatangan.jabatan';
            $map['start_date']                 = 'pemagang.tanggal_mulai';
            $map['tgl_mulai']                  = 'pemagang.tanggal_mulai';
            $map['end_date']                   = 'pemagang.tanggal_selesai';
            $map['tgl_selesai']                = 'pemagang.tanggal_selesai';
            $map['duration']                   = 'pemagang.durasi';
            $map['durasi']                     = 'pemagang.durasi';
            $map['company_logo']               = 'asset.brand.logo';
            $map['asset.company.logo']         = 'asset.brand.logo';
            $map['institution_logo']           = 'asset.institusi.logo';
            $map['asset.institution.logo']     = 'asset.institusi.logo';
        } else {
            // Pemetaan umum tanpa konteks spesifik
            $map['institution_name']           = 'institusi.nama';
            $map['institution_logo']           = 'asset.institusi.logo';
            $map['asset.institution.logo']     = 'asset.institusi.logo';
            $map['company_logo']               = 'asset.brand.logo';
            $map['asset.company.logo']         = 'asset.brand.logo';
            $map['company_name']               = 'brand.nama';
            $map['perusahaan.nama']            = 'brand.nama';
            $map['signatory_name']             = 'brand.penandatangan.nama';
            $map['signatory_position']         = 'brand.penandatangan.jabatan';
            $map['division_name']              = 'pemagang.divisi';
            $map['divisi']                     = 'pemagang.divisi';
            $map['institution_signatory_name'] = 'institusi.penandatangan.nama';
            $map['institution_signatory_title']= 'institusi.penandatangan.jabatan';
            $map['company_signatory_name']     = 'brand.penandatangan.nama';
            $map['company_signatory_title']    = 'brand.penandatangan.jabatan';
        }

        return $map;
    }

    /**
     * Normalisasi token mentah (baik berbentuk {{token}}, [token], atau alias) menjadi kunci kanonis.
     * Menggunakan konteks documentType untuk memecah collision secara aman.
     */
    public static function normalizeToken(string $rawToken, ?string $documentType = null): string
    {
        $clean = trim($rawToken);
        $clean = preg_replace('/^(\{\{|\{|\[)\s*|\s*(\}\}|\}|\])$/', '', $clean);
        $lower = strtolower($clean);

        $map = static::getAliasesMap($documentType);
        return $map[$lower] ?? $clean;
    }

    /**
     * Dapatkan metadata untuk satu token kanonis atau aliasnya, mempertimbangkan jenis dokumen.
     */
    public static function getDefinition(string $token, ?string $documentType = null): ?array
    {
        $canonical = static::normalizeToken($token, $documentType);
        $definitions = static::getDefinitions();
        return $definitions[$canonical] ?? null;
    }

    /**
     * Dapatkan daftar token yang diizinkan untuk jenis dokumen tertentu.
     */
    public static function getTokensForDocumentType(string $documentType): array
    {
        $docType = strtolower(trim($documentType));
        // Normalisasi alias jenis dokumen
        $docType = match ($docType) {
            'sertifikat'  => self::DOC_TYPE_CERTIFICATE,
            'penilaian'   => self::DOC_TYPE_ASSESSMENT,
            'rekomendasi' => self::DOC_TYPE_RECOMMENDATION,
            default       => $docType,
        };

        $result = [];
        foreach (static::getDefinitions() as $key => $def) {
            if (in_array($docType, $def['allowed_document_types'], true)) {
                $result[$key] = $def;
            }
        }
        return $result;
    }

    /**
     * Dapatkan daftar token wajib untuk suatu jenis dokumen.
     */
    public static function getRequiredTokens(string $documentType): array
    {
        $tokens = static::getTokensForDocumentType($documentType);
        $required = [];
        foreach ($tokens as $key => $def) {
            if (($def['required_policy'] ?? '') === self::POLICY_REQUIRED) {
                $required[] = $key;
            }
        }
        return $required;
    }

    /**
     * Diagnosis variabel wajib yang belum terpenuhi untuk jenis dokumen.
     *
     * @param string $documentType
     * @param array $resolvedVariables
     * @return array<string>
     */
    public static function diagnoseMissing(string $documentType, array $resolvedVariables): array
    {
        $requiredTokens = static::getRequiredTokens($documentType);
        $missing = [];
        $flatVars = $resolvedVariables['variables'] ?? $resolvedVariables;

        foreach ($requiredTokens as $canonical) {
            $def = static::getDefinition($canonical, $documentType);
            $val = $flatVars[$canonical] ?? null;

            if (($val === null || trim((string)$val) === '' || trim((string)$val) === '-') && isset($def['aliases'])) {
                foreach ($def['aliases'] as $alias) {
                    if (isset($flatVars[$alias]) && trim((string)$flatVars[$alias]) !== '' && trim((string)$flatVars[$alias]) !== '-') {
                        $val = $flatVars[$alias];
                        break;
                    }
                }
            }

            // Jika kebijakan null adalah DASH atau nilai "-" adalah fallback yang sah, jangan anggap hilang
            $nullPolicy = $def['null_policy'] ?? self::NULL_POLICY_EMPTY;
            if ($nullPolicy === self::NULL_POLICY_DASH && ($val === '-' || $val === null || trim((string)$val) === '')) {
                continue;
            }

            if ($val === null || trim((string)$val) === '') {
                $missing[] = ($def['label'] ?? $canonical) . " ({$canonical})";
            }
        }

        return $missing;
    }

    /**
     * Dapatkan daftar variabel yang dikelompokkan berdasarkan kategori untuk tampilan UI editor,
     * secara opsional disaring menurut jenis dokumen.
     */
    public static function getGroupedForEditor(?string $documentType = null): array
    {
        $definitions = $documentType ? static::getTokensForDocumentType($documentType) : static::getDefinitions();
        $grouped = [];

        foreach ($definitions as $item) {
            $cat = $item['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = [
                'name'         => $item['label'],
                'token'        => '{{' . $item['token'] . '}}',
                'bracket'      => '[' . ($item['aliases'][0] ?? $item['token']) . ']',
                'canonical'    => $item['token'],
                'type'         => $item['type'] ?? $item['data_type'],
                'data_type'    => $item['data_type'],
                'sample'       => $item['sample'],
                'required'     => ($item['required_policy'] ?? '') === self::POLICY_REQUIRED,
                'source_owner' => $item['source_owner_policy'] ?? self::OWNER_SYSTEM,
                'description'  => "Sumber: {$item['source']}",
            ];
        }

        return $grouped;
    }
}
