<?php

namespace App\Models;

use App\Services\MouDocumentRenderer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MouTemplate extends Model
{
    use HasFactory, SoftDeletes;

    public const TOKEN_TYPE_TEXT  = 'text';
    public const TOKEN_TYPE_DATE  = 'computed_date';
    public const TOKEN_TYPE_IMAGE = 'image';

    public const STATE_DRAFT     = 'draft';
    public const STATE_PUBLISHED = 'published';
    public const STATE_ARCHIVED  = 'archived';

    protected $table = 'mou_templates';

    protected $fillable = [
        'public_uuid',
        'name',
        'version_number',
        'published_version',
        'published_at',
        'content_sha256',
        'lock_version',
        'template_html',
        'allowed_placeholders',
        'default_values',
        'is_active',
        'is_default',
        'state',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'version_number'       => 'integer',
        'published_version'    => 'integer',
        'published_at'         => 'datetime',
        'lock_version'         => 'integer',
        'allowed_placeholders' => 'array',
        'default_values'       => 'array',
        'is_active'            => 'boolean',
        'is_default'           => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (MouTemplate $template) {
            if (empty($template->public_uuid)) {
                $template->public_uuid = (string) Str::uuid();
            }
            if (empty($template->allowed_placeholders)) {
                $template->allowed_placeholders = array_keys(static::getDefaultPlaceholders());
            }
        });

        static::saving(function (MouTemplate $template) {
            if (!empty($template->template_html)) {
                $template->template_html = static::sanitizeTemplateHtml($template->template_html);
            }
        });
    }

    /**
     * Sanitasi ketat template HTML menggunakan allowlist DOM & normalisasi penomoran otomatis Pasal/Ayat.
     */
    public static function sanitizeTemplateHtml(?string $html): string
    {
        return MouDocumentRenderer::sanitizeEditableTemplate($html, true);
    }

    /**
     * Scope untuk template yang aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeDraft($query)
    {
        return $query->where('state', self::STATE_DRAFT);
    }

    public function scopePublished($query)
    {
        return $query->where('state', self::STATE_PUBLISHED);
    }

    public function scopeArchived($query)
    {
        return $query->where('state', self::STATE_ARCHIVED);
    }

    public function isDraft(): bool
    {
        return ($this->state ?? '') === self::STATE_DRAFT;
    }

    public function isPublished(): bool
    {
        return ($this->state ?? '') === self::STATE_PUBLISHED;
    }

    public function isArchived(): bool
    {
        return ($this->state ?? '') === self::STATE_ARCHIVED;
    }

    /**
     * User pembuat template.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creator(): BelongsTo
    {
        return $this->createdBy();
    }

    /**
     * User pengubah template terakhir.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function updater(): BelongsTo
    {
        return $this->updatedBy();
    }

    /**
     * Metadata lengkap seluruh placeholder berdasarkan kategori dan tipe datanya.
     */
    public static function getPlaceholderMetadata(): array
    {
        return [
            // Pihak Kesatu (Institusi Mitra)
            'institution_name' => [
                'label' => 'Nama Institusi / Kampus / Sekolah Mitra',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_mou_number' => [
                'label' => 'Nomor Surat Perjanjian Pihak Institusi',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_signatory_name' => [
                'label' => 'Nama Pejabat Penandatangan Institusi',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_signatory_title' => [
                'label' => 'Jabatan Penandatangan Institusi (Rektor / Dekan / Kepala Sekolah)',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_address' => [
                'label' => 'Alamat Lengkap Institusi',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_correspondence_unit' => [
                'label' => 'U.p. / Unit Korespondensi Institusi',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_phone' => [
                'label' => 'Nomor Telepon Institusi',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_fax' => [
                'label' => 'Nomor Fax Institusi',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_email' => [
                'label' => 'Email Resmi Institusi',
                'group' => 'Pihak Kesatu (Institusi)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'institution_logo' => [
                'label' => 'Logo Pihak Kesatu (Institusi Mitra)',
                'group' => 'Logo & Identitas Visual',
                'type'  => self::TOKEN_TYPE_IMAGE,
            ],

            // Pihak Kedua (Seven Inc)
            'company_name' => [
                'label' => 'Nama Perusahaan (Default: SEVEN INC)',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_mou_number' => [
                'label' => 'Nomor Surat Perjanjian Seven Inc',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_signatory_name' => [
                'label' => 'Nama Pimpinan Penandatangan Seven Inc',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_signatory_title' => [
                'label' => 'Jabatan Pimpinan Seven Inc (Pimpinan / Direktur)',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_address' => [
                'label' => 'Alamat Kantor Seven Inc',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_correspondence_unit' => [
                'label' => 'U.p. Korespondensi Seven Inc (Default: CEO)',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_phone' => [
                'label' => 'Nomor Telepon Seven Inc',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_fax' => [
                'label' => 'Nomor Fax Seven Inc',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_email' => [
                'label' => 'Email Resmi Seven Inc',
                'group' => 'Pihak Kedua (Perusahaan)',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'company_logo' => [
                'label' => 'Logo Resmi Pihak Kedua (Seven Inc)',
                'group' => 'Logo & Identitas Visual',
                'type'  => self::TOKEN_TYPE_IMAGE,
            ],

            // Waktu, Tempat & Masa Berlaku
            'agreement_place' => [
                'label' => 'Kota / Tempat Penandatanganan',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_TEXT,
            ],
            'agreement_day' => [
                'label' => 'Nama Hari Penandatanganan (Otomatis, cth: Sabtu)',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_DATE,
            ],
            'agreement_date_words' => [
                'label' => 'Kalimat Tanggal Terbilang Bahasa Indonesia (Otomatis)',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_DATE,
            ],
            'agreement_date_short' => [
                'label' => 'Tanggal Angka Pendek DD-MM-YYYY (Otomatis, cth: 24-05-2025)',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_DATE,
            ],
            'agreement_date' => [
                'label' => 'Tanggal Penandatanganan Dokumen (cth: 24 Mei 2025)',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_DATE,
            ],
            'effective_start' => [
                'label' => 'Tanggal Mulai Berlaku Kerja Sama',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_DATE,
            ],
            'effective_end' => [
                'label' => 'Tanggal Berakhir Masa Berlaku Kerja Sama',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_DATE,
            ],
            'agreement_duration' => [
                'label' => 'Jangka Waktu Kerja Sama (cth: 5 (lima) tahun)',
                'group' => 'Tempat & Waktu Perjanjian',
                'type'  => self::TOKEN_TYPE_DATE,
            ],
        ];
    }

    /**
     * Daftar seluruh placeholder yang didukung beserta penjelasannya (key => label).
     */
    public static function getDefaultPlaceholders(): array
    {
        $result = [];
        foreach (static::getPlaceholderMetadata() as $key => $meta) {
            $result[$key] = $meta['label'];
        }
        return $result;
    }

    /**
     * Nilai baku identitas Pihak Kedua (Seven Inc) sesuai dokumen Word standar.
     */
    public static function getDefaultCompanyValues(): array
    {
        return [
            'company_name'                => 'SEVEN INC',
            'company_signatory_name'      => 'Rekario Danny Sanjaya, S.Kom.',
            'company_signatory_title'     => 'Pimpinan',
            'company_correspondence_unit' => 'CEO',
            'company_address'             => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
            'company_phone'               => '089529002944',
            'company_fax'                 => '-',
            'company_email'               => 'magangjogjadotcom@gmail.com',
            'agreement_place'             => 'Yogyakarta',
            'agreement_duration'          => '5 (lima) tahun',
        ];
    }

    /**
     * HTML tabel Kotak Paraf standar di bagian Footer yang dapat diedit & diubah ukurannya secara bebas di Tiptap.
     */
    public static function getDefaultFooterParafHtml(
        string $firstPartyLabel = 'Paraf PIHAK KESATU',
        string $secondPartyLabel = 'Paraf PIHAK KEDUA'
    ): string {
        $safeFirst  = e($firstPartyLabel);
        $safeSecond = e($secondPartyLabel);

        return <<<HTML
<table class="mou-paraf-table" data-table-width="220pt" style="width: 220pt; border-collapse: collapse; border: 1px solid #000000; font-size: 8.5pt;">
    <colgroup>
        <col width="147" style="width: 147px">
        <col width="147" style="width: 147px">
    </colgroup>
    <tbody>
        <tr>
            <td colwidth="147" data-cell-width="50%" style="width: 50%; border: 1px solid #000000; padding: 2pt 6pt; text-align: center; font-weight: bold;"><p style="text-align: center; margin: 0;"><strong>{$safeFirst}</strong></p></td>
            <td colwidth="147" data-cell-width="50%" style="width: 50%; border: 1px solid #000000; padding: 2pt 6pt; text-align: center; font-weight: bold;"><p style="text-align: center; margin: 0;"><strong>{$safeSecond}</strong></p></td>
        </tr>
        <tr data-row-height="18pt" style="height: 18pt;">
            <td colwidth="147" data-cell-width="50%" style="width: 50%; height: 18pt; border: 1px solid #000000; padding: 2pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;"></p></td>
            <td colwidth="147" data-cell-width="50%" style="width: 50%; height: 18pt; border: 1px solid #000000; padding: 2pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;"></p></td>
        </tr>
    </tbody>
</table>
HTML;
    }

    /**
     * Konfigurasi halaman kertas A4 standar (Margin 2.54cm / 1 inch, Times New Roman 12pt, Header/Footer V3).
     */
    public static function getDefaultPageConfig(): array
    {
        return [
            '_schema_version'          => 3,
            'paper_size'               => 'A4',
            'orientation'              => 'portrait',
            'margin_top_mm'            => 25.4,
            'margin_bottom_mm'         => 25.4,
            'margin_left_mm'           => 25.4,
            'margin_right_mm'          => 25.4,
            'header_distance_mm'       => 12.0,
            'footer_distance_mm'       => 10.0,
            'different_first_page'     => false,
            'header_html'              => '',
            'footer_html'              => static::getDefaultFooterParafHtml(),
            'first_page_header_html'   => '',
            'first_page_footer_html'   => '',
            'font_family'              => 'Times New Roman',
            'font_size_pt'             => 12,
            'line_height'              => 1.15,
            'show_paraf_box'           => true,
            'show_paraf_footer'        => true,
            'show_header_logos'        => true,
            'show_watermark'           => false,
            'watermark_opacity'        => 0.05,
            'paraf_first_party_label'  => 'Paraf PIHAK KESATU',
            'paraf_second_party_label' => 'Paraf PIHAK KEDUA',
            'show_page_number'         => true,
            'show_page_numbers'        => true,
        ];
    }

    /**
     * Aset gambar default (menggunakan placeholder netral untuk institusi mitra agar tidak memakai logo UMPO pada institusi lain).
     */
    public static function getDefaultMediaAssets(): array
    {
        return [
            'company_logo'     => '/images/mou/seven-inc-logo.png',
            'institution_logo' => '/images/mou/institution-logo-placeholder.png',
        ];
    }

    /**
     * Ambil nilai default perusahaan dari instance template (memisahkan namespace internal).
     */
    public function getCompanyDefaultValues(): array
    {
        $raw = $this->default_values ?? [];
        $companyDefaults = static::getDefaultCompanyValues();

        foreach ($companyDefaults as $key => $defaultVal) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '') {
                $companyDefaults[$key] = $raw[$key];
            }
        }

        return $companyDefaults;
    }

    /**
     * Ambil konfigurasi halaman A4 dari instance template (kompatibel penuh dengan V1, V2, dan V3).
     */
    public function getPageConfig(): array
    {
        $raw = $this->default_values['_page_config'] ?? [];
        $rawArray = is_array($raw) ? $raw : [];
        $merged = array_merge(static::getDefaultPageConfig(), $rawArray);

        // Jika template lama (V1/V2) belum memiliki footer_html eksplisit tetapi menampilkan kotak paraf,
        // bangun tabel paraf default sesuai label yang tersimpan agar langsung dapat diedit di V3.
        if (!array_key_exists('footer_html', $rawArray) || $rawArray['footer_html'] === null) {
            $showParaf = (bool) ($merged['show_paraf_footer'] ?? $merged['show_paraf_box'] ?? true);
            $merged['footer_html'] = $showParaf
                ? static::getDefaultFooterParafHtml(
                    (string) ($merged['paraf_first_party_label'] ?? 'Paraf PIHAK KESATU'),
                    (string) ($merged['paraf_second_party_label'] ?? 'Paraf PIHAK KEDUA')
                )
                : '';
        }

        return $merged;
    }

    /**
     * Ambil snapshot ProseMirror JSON jika tersimpan valid pada default_values['_editor_json']
     * dan cocok dengan revisi template_html saat ini.
     */
    public function getEditorJson(): ?array
    {
        $rawDefaults = $this->default_values ?? [];
        $json = $rawDefaults['_editor_json'] ?? null;

        if (!is_array($json) || ($json['type'] ?? '') !== 'doc' || empty($json['content']) || !is_array($json['content'])) {
            return null;
        }

        // Rekonsiliasi hash SHA-256 jika tersedia agar jika template_html diubah langsung, JSON lama tidak menimpa HTML baru
        $savedHash = $rawDefaults['_editor_html_sha256'] ?? null;
        if (is_string($savedHash) && $savedHash !== '') {
            $currentHash = hash('sha256', trim((string) $this->template_html));
            if (!hash_equals($savedHash, $currentHash)) {
                return null;
            }
        }

        return $json;
    }

    /**
     * Ambil konfigurasi aset gambar dari instance template.
     */
    public function getMediaAssets(): array
    {
        $raw = $this->default_values['_media_assets'] ?? [];
        return array_merge(static::getDefaultMediaAssets(), is_array($raw) ? $raw : []);
    }

    /**
     * Render template HTML dengan mengganti placeholder secara aman (safe token replacement).
     */
    public function renderContent(array $data, bool $forPublishedSnapshot = false): string
    {
        return MouDocumentRenderer::renderTemplate($this, $data, $forPublishedSnapshot);
    }

    /**
     * Naskah template default utama sesuai 100% dokumen Word Nota Kesepahaman Tri Dharma Perguruan Tinggi.
     */
    public static function getDefaultTemplateContent(): string
    {
        return static::getWordStandardTemplateContent();
    }

    /**
     * Naskah lengkap kata demi kata sesuai dokumen Word Nota Kesepahaman Tri Dharma Perguruan Tinggi.
     * Menggunakan elemen pengolah kata bebas (paragraf & tabel standar yang dapat diedit, dihapus, atau diubah ukurannya).
     */
    public static function getWordStandardTemplateContent(): string
    {
        return <<<'HTML'
<div class="mou-document mou-doc-canvas">
    <p style="text-align: center; margin-bottom: 6pt;"><strong>NOTA KESEPAHAMAN</strong></p>
    <p style="text-align: center; margin-bottom: 6pt;"><strong>ANTARA</strong></p>
    <p style="text-align: center; text-transform: uppercase; margin-bottom: 2pt;"><strong>[institution_name]</strong></p>
    <p style="text-align: center; text-transform: uppercase; margin-bottom: 8pt;"><strong>DENGAN [company_name]</strong></p>
    <p style="text-align: center; margin-bottom: 2pt;"><strong>TENTANG</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>PELAKSANAAN TRI DHARMA PERGURUAN TINGGI</strong></p>
    <p style="text-align: center; margin-bottom: 2pt;"><strong>NOMOR: [institution_mou_number]</strong></p>
    <p style="text-align: center; margin-bottom: 12pt;"><strong>NOMOR: [company_mou_number]</strong></p>

    <p style="text-align: justify; margin-bottom: 8pt;">Pada hari ini, [agreement_day], [agreement_date_words] ([agreement_date_short]), bertempat di [agreement_place], yang bertanda tangan di bawah ini :</p>

    <table class="mou-table-borderless" data-table-style="borderless" data-table-width="100%" style="width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 10pt;">
        <colgroup>
            <col width="18" style="width: 18px">
            <col width="200" style="width: 200px">
            <col width="14" style="width: 14px">
            <col width="368" style="width: 368px">
        </colgroup>
        <tbody>
            <tr>
                <td colwidth="18" data-cell-width="3%" style="width: 3%; vertical-align: top; padding-right: 0;"><p style="margin: 0;">1.</p></td>
                <td colwidth="200" data-cell-width="33%" style="width: 33%; vertical-align: top; padding-left: 2pt; word-break: break-all; word-wrap: break-word;"><p style="margin: 0;"><strong>[institution_signatory_name]</strong></p></td>
                <td colwidth="14" data-cell-width="2%" style="width: 2%; vertical-align: top; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td colwidth="368" data-cell-width="62%" style="width: 62%; vertical-align: top; text-align: justify; word-break: break-all; word-wrap: break-word;"><p style="text-align: justify; margin: 0;">[institution_signatory_title] [institution_name], berkedudukan di [institution_address], oleh karena itu sah mewakili dan bertindak untuk dan atas nama [institution_name], selanjutnya disebut <strong>PIHAK KESATU</strong>; dan</p></td>
            </tr>
            <tr>
                <td colwidth="18" data-cell-width="3%" style="width: 3%; vertical-align: top; padding-right: 0;"><p style="margin: 0;">2.</p></td>
                <td colwidth="200" data-cell-width="33%" style="width: 33%; vertical-align: top; padding-left: 2pt; word-break: break-all; word-wrap: break-word;"><p style="margin: 0;"><strong>[company_signatory_name]</strong></p></td>
                <td colwidth="14" data-cell-width="2%" style="width: 2%; vertical-align: top; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td colwidth="368" data-cell-width="62%" style="width: 62%; vertical-align: top; text-align: justify; word-break: break-all; word-wrap: break-word;"><p style="text-align: justify; margin: 0;">[company_signatory_title] [company_name], Berkedudukan di [company_address], oleh karena itu sah mewakili dan bertindak untuk dan atas nama [company_name], selanjutnya disebut <strong>PIHAK KEDUA</strong>.</p></td>
            </tr>
        </tbody>
    </table>

    <p style="text-align: justify; margin-bottom: 6pt;"><strong>PIHAK KESATU</strong> dan <strong>PIHAK KEDUA</strong> untuk selanjutnya secara bersama-sama disebut <strong>PARA PIHAK</strong>.</p>
    <p style="text-align: justify; margin-bottom: 10pt;"><strong>PARA PIHAK</strong> bersepakat untuk mengadakan kerja sama dalam bidang pendidikan, penelitian, pengabdian kepada masyarakat, dan pengembangan sumber daya dengan ketentuan sebagai berikut.</p>

    <p style="text-align: center; margin-top: 10pt; margin-bottom: 0pt;"><strong>Pasal 1</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>MAKSUD DAN TUJUAN</strong></p>
    <p style="text-align: justify; margin-left: 24pt; text-indent: -24pt; margin-bottom: 4pt;"><strong>(1)</strong> Maksud Nota Kesepahaman ini adalah sebagai landasan dalam rangka pelaksanaan kerja sama yang akan disusun oleh <strong>PARA PIHAK</strong> sesuai dengan ruang lingkup Nota Kesepahaman ini.</p>
    <p style="text-align: justify; margin-left: 24pt; text-indent: -24pt; margin-bottom: 8pt;"><strong>(2)</strong> Tujuan Nota Kesepahaman ini adalah untuk saling mendukung kegiatan <strong>PARA PIHAK</strong> dalam rangka kerja sama yang berkaitan dengan bidang bidang pendidikan, penelitian, pengabdian kepada masyarakat, dan pengembangan sumber daya.</p>

    <p style="text-align: center; margin-top: 10pt; margin-bottom: 0pt;"><strong>Pasal 2</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>RUANG LINGKUP</strong></p>
    <p style="text-align: justify; margin-bottom: 8pt;">Ruang lingkup kerja sama ini meliputi bidang pendidikan, penelitian, pengabdian kepada masyarakat, dan pengembangan sumber daya <strong>PARA PIHAK</strong>.</p>

    <p style="text-align: center; margin-top: 10pt; margin-bottom: 0pt;"><strong>Pasal 3</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>PELAKSANAAN KEGIATAN</strong></p>
    <p style="text-align: justify; margin-bottom: 8pt;">Pelaksanaan kegiatan yang merupakan tindak lanjut dari Nota Kesepahaman ini akan diatur dalam Perjanjian Kerja Sama melalui pejabat yang ditunjuk dan diberi kewenangan oleh <strong>PARA PIHAK</strong>.</p>

    <p style="text-align: center; margin-top: 10pt; margin-bottom: 0pt;"><strong>Pasal 4</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>PEMBIAYAAN</strong></p>
    <p style="text-align: justify; margin-bottom: 8pt;">Pembiayaan yang berhubungan dengan tindak lanjut Nota Kesepahaman ini ditetapkan bersama sesuai dengan kebutuhan yang disepakati oleh <strong>PARA PIHAK</strong>. dan dituangkan dalam Perjanjian Kerja Sama.</p>

    <p style="text-align: center; margin-top: 10pt; margin-bottom: 0pt;"><strong>Pasal 5</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>KORESPONDENSI</strong></p>
    <p style="text-align: justify; margin-left: 24pt; text-indent: -24pt; margin-bottom: 6pt;"><strong>(1)</strong> semua pemberitahuan, permintaan dan atau usulan yang dibuat sehubungan dengan Nota Kesepahaman ini harus dilakukan secara tertulis dan diserahkan secara langsung, melalui pos, email, atau melalui faksimili kepada masing masing <strong>PIHAK</strong> yang alamatnya disebutkan dibawah ini.</p>

    <table class="mou-table-bordered mou-correspondence-table" data-table-style="bordered" data-table-width="100%" style="width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 6pt; margin-bottom: 10pt;">
        <colgroup>
            <col width="132" style="width: 132px">
            <col width="24" style="width: 24px">
            <col width="444" style="width: 444px">
        </colgroup>
        <tbody>
            <tr>
                <td colspan="3" style="border: 1px solid #000000; padding: 3pt 6pt; font-weight: bold;"><p style="margin: 0;"><strong>PIHAK KESATU</strong></p></td>
            </tr>
            <tr>
                <td colwidth="132" data-cell-width="22%" style="width: 22%; border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Instansi</p></td>
                <td colwidth="24" data-cell-width="4%" style="width: 4%; border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td colwidth="444" data-cell-width="74%" style="width: 74%; border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[institution_name]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Alamat</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[institution_address]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">U.p</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[institution_correspondence_unit]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Telepon</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[institution_phone]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Fax</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[institution_fax]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Email</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[institution_email]</p></td>
            </tr>
        </tbody>
    </table>

    <table class="mou-table-bordered mou-correspondence-table" data-table-style="bordered" data-table-width="100%" style="width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 6pt; margin-bottom: 10pt;">
        <colgroup>
            <col width="132" style="width: 132px">
            <col width="24" style="width: 24px">
            <col width="444" style="width: 444px">
        </colgroup>
        <tbody>
            <tr>
                <td colspan="3" style="border: 1px solid #000000; padding: 3pt 6pt; font-weight: bold;"><p style="margin: 0;"><strong>PIHAK KEDUA</strong></p></td>
            </tr>
            <tr>
                <td colwidth="132" data-cell-width="22%" style="width: 22%; border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Instansi</p></td>
                <td colwidth="24" data-cell-width="4%" style="width: 4%; border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td colwidth="444" data-cell-width="74%" style="width: 74%; border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[company_name]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Alamat</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[company_address]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">U.p</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[company_correspondence_unit]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Telepon</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[company_phone]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Fax</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[company_fax]</p></td>
            </tr>
            <tr>
                <td style="border: 1px solid #000000; padding: 3pt 6pt 3pt 18pt;"><p style="margin: 0;">Email</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt; text-align: center;"><p style="text-align: center; margin: 0;">:</p></td>
                <td style="border: 1px solid #000000; padding: 3pt 6pt;"><p style="margin: 0;">[company_email]</p></td>
            </tr>
        </tbody>
    </table>

    <p style="text-align: justify; margin-left: 24pt; text-indent: -24pt; margin-bottom: 8pt;"><strong>(2)</strong> Apabila terdapat perubahan alamat dan/atau data sebagaimana tercantum pada ayat (1) Pasal ini, Maka <strong>PIHAK</strong> yang mengubah alamat dan/atau data tersebut wajib memberitahukan secara tertulis atas perubahan tersebut dalam jangka waktu selambat-lambatnya 30 (tiga puluh) hari kalender kepada <strong>PIHAK</strong> lainnya sebelum alamat tersebut dinyatakan efektif. Apabila perubahan tersebut tidak diberitahukan maka korespondensi atau permintaan lainnya pada alamat terakhir yang diketahui oleh <strong>PIHAK</strong> lainnya dianggap telah diberikan dengan semestinya.</p>

    <p style="text-align: center; margin-top: 10pt; margin-bottom: 0pt;"><strong>Pasal 6</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>KETENTUAN LAIN-LAIN</strong></p>
    <p style="text-align: justify; margin-bottom: 8pt;">Ketentuan dan/atau hal-hal lain yang belum diatur dalam Nota Kesepahaman ini diatur dalam bentuk addendum yang disepakati oleh <strong>PARA PIHAK</strong> dan merupakan bagian yang tidak terpisahkan dari Nota Kesepahaman ini.</p>

    <p style="text-align: center; margin-top: 10pt; margin-bottom: 0pt;"><strong>Pasal 7</strong></p>
    <p style="text-align: center; margin-bottom: 4pt;"><strong>PENUTUP</strong></p>
    <p style="text-align: justify; margin-left: 24pt; text-indent: -24pt; margin-bottom: 4pt;"><strong>(1)</strong> Nota Kesepahaman ini dibuat rangkap dua, bermaterai cukup dan memiliki kekuatan hukum yang sama.</p>
    <p style="text-align: justify; margin-left: 24pt; text-indent: -24pt; margin-bottom: 4pt;"><strong>(2)</strong> Nota Kesepahaman ini berlaku sejak tanggal ditandatangani untuk jangka waktu [agreement_duration] dan dapat diperpanjang atas persetujuan <strong>PARA PIHAK</strong>.</p>
    <p style="text-align: justify; margin-left: 24pt; text-indent: -24pt; margin-bottom: 10pt;"><strong>(3)</strong> Apabila terjadi perselisihan, perbedaan penafsiran terhadap Nota Kesepahaman ini, atau hal-hal lain terkait yang belum secara tersurat dinyatakan dalam Nota Kesepahaman ini, akan diselesaikan secara musyawarah untuk mufakat.</p>

    <table class="mou-signature-table mou-table-borderless" data-table-style="borderless" data-table-width="100%" style="width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 24pt;">
        <colgroup>
            <col width="300" style="width: 300px">
            <col width="300" style="width: 300px">
        </colgroup>
        <tbody>
            <tr>
                <td colwidth="300" data-cell-width="50%" style="width: 50%; text-align: center; font-weight: bold;"><p style="text-align: center; margin: 0;"><strong>PIHAK KESATU,</strong></p></td>
                <td colwidth="300" data-cell-width="50%" style="width: 50%; text-align: center; font-weight: bold;"><p style="text-align: center; margin: 0;"><strong>PIHAK KEDUA,</strong></p></td>
            </tr>
            <tr data-row-height="26mm" style="height: 26mm;">
                <td colwidth="300" data-cell-width="50%" style="width: 50%; height: 26mm; text-align: center;"><p style="text-align: center; margin: 0;"></p></td>
                <td colwidth="300" data-cell-width="50%" style="width: 50%; height: 26mm; text-align: center;"><p style="text-align: center; margin: 0;"></p></td>
            </tr>
            <tr>
                <td colwidth="300" data-cell-width="50%" style="width: 50%; text-align: center; font-weight: bold;"><p style="text-align: center; margin: 0;"><strong>[institution_signatory_name]</strong></p></td>
                <td colwidth="300" data-cell-width="50%" style="width: 50%; text-align: center; font-weight: bold;"><p style="text-align: center; margin: 0;"><strong>[company_signatory_name]</strong></p></td>
            </tr>
        </tbody>
    </table>
</div>
HTML;
    }

    /**
     * Naskah template Nota Kesepahaman Tri Dharma Perguruan Tinggi & Pengembangan SDM (Versi 2).
     */
    public static function getTriDharmaTemplateContent(): string
    {
        return static::getWordStandardTemplateContent();
    }
}
