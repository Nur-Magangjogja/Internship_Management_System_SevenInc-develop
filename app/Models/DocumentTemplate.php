<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DocumentTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'document_templates';

    protected $fillable = [
        'uuid',
        'type',
        'name',
        'description',
        'editor_type',
        'content_html',
        'editor_json',
        'canvas_layout',
        'page_config',
        'is_active',
        'is_default',
        'version_number',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'editor_json'    => 'array',
        'canvas_layout'  => 'array',
        'page_config'    => 'array',
        'is_active'      => 'boolean',
        'is_default'     => 'boolean',
        'version_number' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->version_number)) {
                $model->version_number = 1;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('uuid', $value)
            ->orWhere('id', $value)
            ->firstOrFail();
    }

    // Relations
    public function versions()
    {
        return $this->hasMany(DocumentTemplateVersion::class, 'document_template_id')->orderBy('version_number', 'desc');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // Helpers & Metas
    public static function getDocumentTypes(): array
    {
        return [
            'loa'            => 'Letter of Acceptance (LOA)',
            'skl'            => 'Surat Keterangan Selesai Magang (SKL)',
            'certificate'    => 'Sertifikat Magang',
            'assessment'     => 'Surat Penilaian Magang',
            'recommendation' => 'Surat Rekomendasi Magang',
        ];
    }

    public static function getTypeLabel(string $type): string
    {
        $types = static::getDocumentTypes();
        return $types[$type] ?? ucfirst($type);
    }

    public static function getTypeBadgeClass(string $type): string
    {
        return match ($type) {
            'loa'            => 'bg-blue-100 text-blue-800 border-blue-200',
            'skl'            => 'bg-emerald-100 text-emerald-800 border-emerald-200',
            'certificate'    => 'bg-amber-100 text-amber-800 border-amber-200',
            'assessment'     => 'bg-purple-100 text-purple-800 border-purple-200',
            'recommendation' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
            default          => 'bg-slate-100 text-slate-800 border-slate-200',
        };
    }

    public static function getDefaultPageConfig(string $type = 'loa'): array
    {
        if ($type === 'certificate') {
            return [
                'paper_size'          => 'A4',
                'orientation'         => 'landscape',
                'width_mm'            => 297.0,
                'height_mm'           => 210.0,
                'margin_top_mm'       => 0.0,
                'margin_bottom_mm'    => 0.0,
                'margin_left_mm'      => 0.0,
                'margin_right_mm'     => 0.0,
                'background_image'    => '/images/certificate/certificate-bg.png',
                'background_color'    => '#ffffff',
            ];
        }

        return [
            'paper_size'          => 'A4',
            'orientation'         => 'portrait',
            'width_mm'            => 210.0,
            'height_mm'           => 297.0,
            'margin_top_mm'       => 20.0,
            'margin_bottom_mm'    => 20.0,
            'margin_left_mm'      => 20.0,
            'margin_right_mm'     => 20.0,
            'font_family'         => 'Times New Roman',
            'font_size_pt'        => 11,
            'line_height'         => 1.3,
            'header_distance_mm'  => 10.0,
            'footer_distance_mm'  => 10.0,
        ];
    }

    public static function getDefaultPlaceholders(): array
    {
        return [
            '{{pemagang.nama}}' => [
                'label'       => 'Nama Lengkap Pemagang',
                'group'       => 'Data Pemagang',
                'example'     => 'Ananda Pratama, S.Kom.',
                'description' => 'Nama lengkap peserta magang sesuai kartu identitas / pendaftaran.',
            ],
            '{{pemagang.nim}}' => [
                'label'       => 'NIM / NIS Pemagang',
                'group'       => 'Data Pemagang',
                'example'     => '2101020045',
                'description' => 'Nomor induk mahasiswa atau nomor induk siswa.',
            ],
            '{{pemagang.institusi}}' => [
                'label'       => 'Institusi / Kampus / Sekolah',
                'group'       => 'Data Pemagang',
                'example'     => 'Universitas Gadjah Mada',
                'description' => 'Nama perguruan tinggi atau SMK asal peserta magang.',
            ],
            '{{magang.divisi}}' => [
                'label'       => 'Divisi / Posisi Magang',
                'group'       => 'Detail Magang',
                'example'     => 'Web Development & Software Engineering',
                'description' => 'Divisi atau spesialisasi pemagang di Seven Inc.',
            ],
            '{{magang.tanggal_mulai}}' => [
                'label'       => 'Tanggal Mulai Magang',
                'group'       => 'Detail Magang',
                'example'     => '1 Februari 2026',
                'description' => 'Tanggal awal pelaksanaan program magang.',
            ],
            '{{magang.tanggal_selesai}}' => [
                'label'       => 'Tanggal Selesai Magang',
                'group'       => 'Detail Magang',
                'example'     => '31 Juli 2026',
                'description' => 'Tanggal akhir pelaksanaan program magang.',
            ],
            '{{magang.durasi}}' => [
                'label'       => 'Durasi Magang',
                'group'       => 'Detail Magang',
                'example'     => '6 (enam) bulan',
                'description' => 'Lama durasi magang yang ditempuh.',
            ],
            '{{dokumen.nomor}}' => [
                'label'       => 'Nomor Dokumen / Sertifikat',
                'group'       => 'Informasi Dokumen',
                'example'     => '042/LOA/HRD-SEVEN/II/2026',
                'description' => 'Nomor surat keluar atau nomor serial sertifikat.',
            ],
            '{{dokumen.tanggal}}' => [
                'label'       => 'Tanggal Penerbitan Dokumen',
                'group'       => 'Informasi Dokumen',
                'example'     => '1 Februari 2026',
                'description' => 'Tanggal pengesahan / penerbitan surat/sertifikat.',
            ],
            '{{dokumen.tempat}}' => [
                'label'       => 'Tempat Penerbitan Dokumen',
                'group'       => 'Informasi Dokumen',
                'example'     => 'Yogyakarta',
                'description' => 'Kota penandatanganan dokumen resmi.',
            ],
            '{{perusahaan.nama}}' => [
                'label'       => 'Nama Perusahaan',
                'group'       => 'Data Perusahaan',
                'example'     => 'SEVEN INC (PT Tujuh Kreasi Persada)',
                'description' => 'Nama resmi badan usaha / entitas penyelenggara.',
            ],
            '{{perusahaan.alamat}}' => [
                'label'       => 'Alamat Perusahaan',
                'group'       => 'Data Perusahaan',
                'example'     => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'description' => 'Alamat kantor operasional / korespondensi.',
            ],
            '{{perusahaan.pimpinan}}' => [
                'label'       => 'Nama Pimpinan Perusahaan',
                'group'       => 'Data Perusahaan',
                'example'     => 'Rekario Danny Sanjaya, S.Kom.',
                'description' => 'Nama lengkap pimpinan / direktur penandatangan surat.',
            ],
            '{{perusahaan.jabatan}}' => [
                'label'       => 'Jabatan Pimpinan',
                'group'       => 'Data Perusahaan',
                'example'     => 'Direktur Utama',
                'description' => 'Jabatan struktural penandatangan resmi.',
            ],
            '{{asset.company.logo}}' => [
                'label'       => 'Logo Resmi Perusahaan',
                'group'       => 'Perpustakaan Aset',
                'example'     => '/images/mou/seven-inc-logo.png',
                'description' => 'Aset grafis logo Seven Inc.',
            ],
            '{{asset.institution.logo}}' => [
                'label'       => 'Logo Institusi Pemagang',
                'group'       => 'Perpustakaan Aset',
                'example'     => '/images/mou/institution-logo-placeholder.png',
                'description' => 'Logo kampus atau sekolah asal pemagang.',
            ],
            '{{asset.signature}}' => [
                'label'       => 'Tanda Tangan Pimpinan',
                'group'       => 'Perpustakaan Aset',
                'example'     => '/images/mou/signature-sample.png',
                'description' => 'Tanda tangan digital pimpinan berlatar transparan.',
            ],
            '{{asset.stamp}}' => [
                'label'       => 'Stempel Resmi Perusahaan',
                'group'       => 'Perpustakaan Aset',
                'example'     => '/images/mou/stamp-sample.png',
                'description' => 'Cap / stempel basah Seven Inc resmi transparan.',
            ],
            '{{asset.background}}' => [
                'label'       => 'Background Template Sertifikat',
                'group'       => 'Perpustakaan Aset',
                'example'     => '/images/certificate/certificate-bg.png',
                'description' => 'Gambar bingkai / latar belakang visual sertifikat.',
            ],
        ];
    }

    public static function getMockPlaceholderData(): array
    {
        return static::getDefaultMockData();
    }

    public static function getDefaultMockData(): array
    {
        $year = date('Y');
        return [
            'pemagang' => [
                'nama'      => 'Ananda Pratama, S.Kom.',
                'nim'       => '2101020045',
                'institusi' => 'Universitas Gadjah Mada',
            ],
            'magang' => [
                'divisi'          => 'Web Development & Software Engineering',
                'tanggal_mulai'   => '1 Februari ' . $year,
                'tanggal_selesai' => '31 Juli ' . $year,
                'durasi'          => '6 (enam) bulan',
            ],
            'dokumen' => [
                'nomor'   => '042/DOC/HRD-SEVEN/II/' . $year,
                'tanggal' => '1 Februari ' . $year,
                'tempat'  => 'Yogyakarta',
            ],
            'perusahaan' => [
                'nama'     => 'SEVEN INC',
                'alamat'   => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'pimpinan' => 'Rekario Danny Sanjaya, S.Kom.',
                'jabatan'  => 'Direktur Utama',
            ],
            'asset' => [
                'company' => [
                    'logo' => '/images/mou/seven-inc-logo.png',
                ],
                'institution' => [
                    'logo' => '/images/mou/institution-logo-placeholder.png',
                ],
                'signature'  => '/images/mou/signature-sample.png',
                'stamp'      => '/images/mou/stamp-sample.png',
                'background' => '/images/certificate/certificate-bg.png',
            ],
        ];
    }

    /**
     * Konten template awal terstruktur untuk setiap tipe dokumen.
     */
    public static function getDefaultStandardContent(string $type): string
    {
        return match ($type) {
            'loa' => <<<HTML
<table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
    <tr>
        <td style="width: 110px; vertical-align: middle;">
            <img src="{{asset.company.logo}}" alt="Logo Perusahaan" style="width: 95px; height: auto;" />
        </td>
        <td style="text-align: center; vertical-align: middle;">
            <h1 style="margin: 0; font-size: 16pt; font-weight: bold; text-transform: uppercase;">{{perusahaan.nama}}</h1>
            <p style="margin: 4px 0 0 0; font-size: 10pt; line-height: 1.2;">{{perusahaan.alamat}}<br>Telp: 089529002944 | Email: magangjogjadotcom@gmail.com</p>
        </td>
    </tr>
</table>
<div style="border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-top: 5px; margin-bottom: 16px;"></div>

<h2 style="text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; margin-bottom: 4px;">LETTER OF ACCEPTANCE (LOA)</h2>
<p style="text-align: center; font-size: 10.5pt; margin-top: 0; margin-bottom: 20px;">Nomor: {{dokumen.nomor}}</p>

<p>Kepada Yth.<br>
Pimpinan / Kepala Program Studi<br>
<strong>{{pemagang.institusi}}</strong><br>
di Tempat</p>

<p style="text-align: justify; text-indent: 28px;">Dengan hormat, sehubungan dengan permohonan pelaksanaan program Praktik Kerja Lapangan (PKL) / Magang Mahasiswa di {{perusahaan.nama}}, bersama surat ini kami menyampaikan bahwa peserta berikut:</p>

<table style="width: 100%; border-collapse: collapse; margin: 12px 0 16px 28px;">
    <tr>
        <td style="width: 140px; padding: 3px 0;"><strong>Nama Lengkap</strong></td>
        <td style="width: 15px; padding: 3px 0;">:</td>
        <td style="padding: 3px 0;"><strong>{{pemagang.nama}}</strong></td>
    </tr>
    <tr>
        <td style="padding: 3px 0;"><strong>NIM / NIS</strong></td>
        <td style="padding: 3px 0;">:</td>
        <td style="padding: 3px 0;">{{pemagang.nim}}</td>
    </tr>
    <tr>
        <td style="padding: 3px 0;"><strong>Institusi Asal</strong></td>
        <td style="padding: 3px 0;">:</td>
        <td style="padding: 3px 0;">{{pemagang.institusi}}</td>
    </tr>
    <tr>
        <td style="padding: 3px 0;"><strong>Divisi Magang</strong></td>
        <td style="padding: 3px 0;">:</td>
        <td style="padding: 3px 0;">{{magang.divisi}}</td>
    </tr>
    <tr>
        <td style="padding: 3px 0;"><strong>Periode Magang</strong></td>
        <td style="padding: 3px 0;">:</td>
        <td style="padding: 3px 0;">{{magang.tanggal_mulai}} s/d {{magang.tanggal_selesai}} ({{magang.durasi}})</td>
    </tr>
</table>

<p style="text-align: justify; text-indent: 28px;">Dinyatakan <strong>DITERIMA</strong> untuk melaksanakan program magang di perusahaan kami sesuai dengan jadwal dan divisi penempatan yang tercantum di atas. Peserta diharapkan dapat mematuhi segala tata tertib, etika kerja, dan peraturan yang berlaku di lingkungan {{perusahaan.nama}}.</p>

<p style="text-align: justify; text-indent: 28px;">Demikian surat penerimaan ini kami sampaikan. Atas perhatian dan kerjasama yang baik, kami ucapkan terima kasih.</p>

<table style="width: 100%; border-collapse: collapse; margin-top: 35px;">
    <tr>
        <td style="width: 55%;"></td>
        <td style="text-align: center;">
            <p style="margin: 0;">{{dokumen.tempat}}, {{dokumen.tanggal}}<br>{{perusahaan.jabatan}} {{perusahaan.nama}}</p>
            <div style="height: 75px; margin: 8px 0; position: relative;">
                <img src="{{asset.signature}}" alt="Tanda Tangan" style="height: 65px; width: auto; position: relative; z-index: 10;" />
                <img src="{{asset.stamp}}" alt="Stempel" style="height: 70px; width: auto; position: absolute; left: 15%; top: -5px; opacity: 0.85; z-index: 20;" />
            </div>
            <p style="margin: 0; font-weight: bold; text-decoration: underline;">{{perusahaan.pimpinan}}</p>
        </td>
    </tr>
</table>
HTML,
            'skl' => <<<HTML
<table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
    <tr>
        <td style="width: 110px; vertical-align: middle;">
            <img src="{{asset.company.logo}}" alt="Logo Perusahaan" style="width: 95px; height: auto;" />
        </td>
        <td style="text-align: center; vertical-align: middle;">
            <h1 style="margin: 0; font-size: 16pt; font-weight: bold; text-transform: uppercase;">{{perusahaan.nama}}</h1>
            <p style="margin: 4px 0 0 0; font-size: 10pt; line-height: 1.2;">{{perusahaan.alamat}}<br>Telp: 089529002944 | Email: magangjogjadotcom@gmail.com</p>
        </td>
    </tr>
</table>
<div style="border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-top: 5px; margin-bottom: 16px;"></div>

<h2 style="text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; margin-bottom: 4px;">SURAT KETERANGAN SELESAI MAGANG (SKL)</h2>
<p style="text-align: center; font-size: 10.5pt; margin-top: 0; margin-bottom: 24px;">Nomor: {{dokumen.nomor}}</p>

<p style="text-align: justify;">Yang bertanda tangan di bawah ini:</p>

<table style="width: 100%; border-collapse: collapse; margin: 8px 0 16px 28px;">
    <tr>
        <td style="width: 140px; padding: 2px 0;"><strong>Nama</strong></td>
        <td style="width: 15px; padding: 2px 0;">:</td>
        <td style="padding: 2px 0;"><strong>{{perusahaan.pimpinan}}</strong></td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Jabatan</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{perusahaan.jabatan}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Instansi</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{perusahaan.nama}}</td>
    </tr>
</table>

<p style="text-align: justify;">Dengan ini menerangkan dengan sesungguhnya bahwa:</p>

<table style="width: 100%; border-collapse: collapse; margin: 8px 0 16px 28px;">
    <tr>
        <td style="width: 140px; padding: 2px 0;"><strong>Nama</strong></td>
        <td style="width: 15px; padding: 2px 0;">:</td>
        <td style="padding: 2px 0;"><strong>{{pemagang.nama}}</strong></td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>NIM / NIS</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{pemagang.nim}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Institusi</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{pemagang.institusi}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Divisi</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{magang.divisi}}</td>
    </tr>
</table>

<p style="text-align: justify; text-indent: 28px;">Telah menyelesaikan program Praktik Kerja Lapangan (PKL) / Magang di {{perusahaan.nama}} terhitung sejak tanggal <strong>{{magang.tanggal_mulai}}</strong> sampai dengan <strong>{{magang.tanggal_selesai}}</strong> dengan hasil yang <strong>Sangat Baik</strong> serta menunjukkan dedikasi, kedisiplinan, dan integritas tinggi selama masa magang.</p>

<p style="text-align: justify; text-indent: 28px;">Demikian surat keterangan ini kami berikan dengan sebenarnya agar dapat dipergunakan sebagaimana mestinya.</p>

<table style="width: 100%; border-collapse: collapse; margin-top: 35px;">
    <tr>
        <td style="width: 55%;"></td>
        <td style="text-align: center;">
            <p style="margin: 0;">{{dokumen.tempat}}, {{dokumen.tanggal}}<br>{{perusahaan.jabatan}} {{perusahaan.nama}}</p>
            <div style="height: 75px; margin: 8px 0; position: relative;">
                <img src="{{asset.signature}}" alt="Tanda Tangan" style="height: 65px; width: auto; position: relative; z-index: 10;" />
                <img src="{{asset.stamp}}" alt="Stempel" style="height: 70px; width: auto; position: absolute; left: 15%; top: -5px; opacity: 0.85; z-index: 20;" />
            </div>
            <p style="margin: 0; font-weight: bold; text-decoration: underline;">{{perusahaan.pimpinan}}</p>
        </td>
    </tr>
</table>
HTML,
            'assessment' => <<<HTML
<table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
    <tr>
        <td style="width: 110px; vertical-align: middle;">
            <img src="{{asset.company.logo}}" alt="Logo Perusahaan" style="width: 95px; height: auto;" />
        </td>
        <td style="text-align: center; vertical-align: middle;">
            <h1 style="margin: 0; font-size: 16pt; font-weight: bold; text-transform: uppercase;">{{perusahaan.nama}}</h1>
            <p style="margin: 4px 0 0 0; font-size: 10pt; line-height: 1.2;">{{perusahaan.alamat}}<br>Telp: 089529002944 | Email: magangjogjadotcom@gmail.com</p>
        </td>
    </tr>
</table>
<div style="border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-top: 5px; margin-bottom: 16px;"></div>

<h2 style="text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; margin-bottom: 4px;">LEMBAR PENILAIAN PRAKTIK KERJA LAPANGAN</h2>
<p style="text-align: center; font-size: 10.5pt; margin-top: 0; margin-bottom: 18px;">Nomor: {{dokumen.nomor}}</p>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
    <tr>
        <td style="width: 130px; padding: 2px 0;">Nama Pemagang</td>
        <td style="width: 15px;">:</td>
        <td><strong>{{pemagang.nama}}</strong></td>
        <td style="width: 120px; padding: 2px 0;">Divisi Penempatan</td>
        <td style="width: 15px;">:</td>
        <td>{{magang.divisi}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;">NIM / NIS</td>
        <td>:</td>
        <td>{{pemagang.nim}}</td>
        <td style="padding: 2px 0;">Periode Magang</td>
        <td>:</td>
        <td>{{magang.durasi}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;">Institusi Asal</td>
        <td>:</td>
        <td>{{pemagang.institusi}}</td>
        <td style="padding: 2px 0;">Tanggal Penilaian</td>
        <td>:</td>
        <td>{{dokumen.tanggal}}</td>
    </tr>
</table>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 18px;" border="1">
    <thead>
        <tr style="background-color: #f1f5f9; text-align: center; font-weight: bold;">
            <th style="padding: 6px; width: 40px;">No</th>
            <th style="padding: 6px;">Komponen Penilaian</th>
            <th style="padding: 6px; width: 90px;">Bobot (%)</th>
            <th style="padding: 6px; width: 90px;">Nilai (1-100)</th>
            <th style="padding: 6px; width: 100px;">Keterangan</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td style="text-align: center; padding: 5px;">1</td>
            <td style="padding: 5px;">Kedisiplinan, Kehadiran & Ketepatan Waktu</td>
            <td style="text-align: center; padding: 5px;">20%</td>
            <td style="text-align: center; padding: 5px;">92</td>
            <td style="text-align: center; padding: 5px;">Sangat Baik</td>
        </tr>
        <tr>
            <td style="text-align: center; padding: 5px;">2</td>
            <td style="padding: 5px;">Kemampuan Teknis & Penguasaan Tugas Proyek</td>
            <td style="text-align: center; padding: 5px;">30%</td>
            <td style="text-align: center; padding: 5px;">95</td>
            <td style="text-align: center; padding: 5px;">Sangat Baik</td>
        </tr>
        <tr>
            <td style="text-align: center; padding: 5px;">3</td>
            <td style="padding: 5px;">Komunikasi, Kerjasama Tim & Inisiatif</td>
            <td style="text-align: center; padding: 5px;">25%</td>
            <td style="text-align: center; padding: 5px;">90</td>
            <td style="text-align: center; padding: 5px;">Sangat Baik</td>
        </tr>
        <tr>
            <td style="text-align: center; padding: 5px;">4</td>
            <td style="padding: 5px;">Integritas, Etika Kerja & Sikap Profesional</td>
            <td style="text-align: center; padding: 5px;">25%</td>
            <td style="text-align: center; padding: 5px;">94</td>
            <td style="text-align: center; padding: 5px;">Sangat Baik</td>
        </tr>
        <tr style="font-weight: bold; background-color: #f8fafc;">
            <td colspan="3" style="text-align: right; padding: 6px;">NILAI AKHIR RATA-RATA:</td>
            <td style="text-align: center; padding: 6px;">93.0</td>
            <td style="text-align: center; padding: 6px;">GRADE: A</td>
        </tr>
    </tbody>
</table>

<table style="width: 100%; border-collapse: collapse; margin-top: 30px;">
    <tr>
        <td style="width: 55%;"></td>
        <td style="text-align: center;">
            <p style="margin: 0;">{{dokumen.tempat}}, {{dokumen.tanggal}}<br>{{perusahaan.jabatan}} {{perusahaan.nama}}</p>
            <div style="height: 75px; margin: 8px 0; position: relative;">
                <img src="{{asset.signature}}" alt="Tanda Tangan" style="height: 65px; width: auto; position: relative; z-index: 10;" />
                <img src="{{asset.stamp}}" alt="Stempel" style="height: 70px; width: auto; position: absolute; left: 15%; top: -5px; opacity: 0.85; z-index: 20;" />
            </div>
            <p style="margin: 0; font-weight: bold; text-decoration: underline;">{{perusahaan.pimpinan}}</p>
        </td>
    </tr>
</table>
HTML,
            'recommendation' => <<<HTML
<table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
    <tr>
        <td style="width: 110px; vertical-align: middle;">
            <img src="{{asset.company.logo}}" alt="Logo Perusahaan" style="width: 95px; height: auto;" />
        </td>
        <td style="text-align: center; vertical-align: middle;">
            <h1 style="margin: 0; font-size: 16pt; font-weight: bold; text-transform: uppercase;">{{perusahaan.nama}}</h1>
            <p style="margin: 4px 0 0 0; font-size: 10pt; line-height: 1.2;">{{perusahaan.alamat}}<br>Telp: 089529002944 | Email: magangjogjadotcom@gmail.com</p>
        </td>
    </tr>
</table>
<div style="border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-top: 5px; margin-bottom: 16px;"></div>

<h2 style="text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; margin-bottom: 4px;">SURAT REKOMENDASI KERJA</h2>
<p style="text-align: center; font-size: 10.5pt; margin-top: 0; margin-bottom: 24px;">Nomor: {{dokumen.nomor}}</p>

<p style="text-align: justify;">Yang bertanda tangan di bawah ini:</p>

<table style="width: 100%; border-collapse: collapse; margin: 8px 0 16px 28px;">
    <tr>
        <td style="width: 140px; padding: 2px 0;"><strong>Nama</strong></td>
        <td style="width: 15px; padding: 2px 0;">:</td>
        <td style="padding: 2px 0;"><strong>{{perusahaan.pimpinan}}</strong></td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Jabatan</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{perusahaan.jabatan}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Perusahaan</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{perusahaan.nama}}</td>
    </tr>
</table>

<p style="text-align: justify;">Dengan ini memberikan rekomendasi penuh kepada:</p>

<table style="width: 100%; border-collapse: collapse; margin: 8px 0 16px 28px;">
    <tr>
        <td style="width: 140px; padding: 2px 0;"><strong>Nama Lengkap</strong></td>
        <td style="width: 15px; padding: 2px 0;">:</td>
        <td style="padding: 2px 0;"><strong>{{pemagang.nama}}</strong></td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>NIM / NIS</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{pemagang.nim}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Institusi Asal</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{pemagang.institusi}}</td>
    </tr>
    <tr>
        <td style="padding: 2px 0;"><strong>Divisi</strong></td>
        <td style="padding: 2px 0;">:</td>
        <td style="padding: 2px 0;">{{magang.divisi}}</td>
    </tr>
</table>

<p style="text-align: justify; text-indent: 28px;">Yang bersangkutan telah melaksanakan magang di {{perusahaan.nama}} sejak {{magang.tanggal_mulai}} sampai dengan {{magang.tanggal_selesai}}. Selama masa magang, saudara/i {{pemagang.nama}} menunjukkan performa luar biasa, kemampuan komunikasi yang efektif, serta komitmen tinggi dalam menyelesaikan setiap proyek yang diberikan.</p>

<p style="text-align: justify; text-indent: 28px;">Berdasarkan kinerja dan kepribadian profesional tersebut, kami dengan senang hati dan tanpa keraguan merekomendasikan yang bersangkutan untuk dapat berkarir maupun melanjutkan studi di institusi/perusahaan Bapak/Ibu.</p>

<p style="text-align: justify; text-indent: 28px;">Demikian surat rekomendasi ini dibuat dengan sesungguhnya untuk dapat digunakan sebagaimana mestinya.</p>

<table style="width: 100%; border-collapse: collapse; margin-top: 35px;">
    <tr>
        <td style="width: 55%;"></td>
        <td style="text-align: center;">
            <p style="margin: 0;">{{dokumen.tempat}}, {{dokumen.tanggal}}<br>{{perusahaan.jabatan}} {{perusahaan.nama}}</p>
            <div style="height: 75px; margin: 8px 0; position: relative;">
                <img src="{{asset.signature}}" alt="Tanda Tangan" style="height: 65px; width: auto; position: relative; z-index: 10;" />
                <img src="{{asset.stamp}}" alt="Stempel" style="height: 70px; width: auto; position: absolute; left: 15%; top: -5px; opacity: 0.85; z-index: 20;" />
            </div>
            <p style="margin: 0; font-weight: bold; text-decoration: underline;">{{perusahaan.pimpinan}}</p>
        </td>
    </tr>
</table>
HTML,
            default => '<p>Mulai menulis draf dokumen di sini...</p>',
        };
    }

    /**
     * Default Visual Canvas Layout untuk Sertifikat Magang.
     */
    public static function getDefaultCertificateCanvasLayout(): array
    {
        return [
            'canvas' => [
                'width_mm'         => 297.0, // A4 Landscape
                'height_mm'        => 210.0,
                'background_image' => '/images/certificate/certificate-bg.png',
                'background_color' => '#ffffff',
            ],
            'elements' => [
                [
                    'id'          => 'elem_logo',
                    'type'        => 'image',
                    'src'         => '{{asset.company.logo}}',
                    'x_percent'   => 50.0, // centered
                    'y_percent'   => 10.0,
                    'width_px'    => 140,
                    'height_px'   => 60,
                    'align'       => 'center',
                    'z_index'     => 10,
                ],
                [
                    'id'          => 'elem_title',
                    'type'        => 'text',
                    'content'     => 'CERTIFICATE OF INTERNSHIP',
                    'x_percent'   => 50.0,
                    'y_percent'   => 22.0,
                    'width_percent' => 80.0,
                    'font_family' => 'Times New Roman',
                    'font_size_pt'=> 32,
                    'font_weight' => 'bold',
                    'color'       => '#1e293b',
                    'align'       => 'center',
                    'letter_spacing_px' => 3,
                    'z_index'     => 10,
                ],
                [
                    'id'          => 'elem_subtitle',
                    'type'        => 'text',
                    'content'     => 'DIBERIKAN KEPADA :',
                    'x_percent'   => 50.0,
                    'y_percent'   => 32.0,
                    'width_percent' => 70.0,
                    'font_family' => 'Arial',
                    'font_size_pt'=> 12,
                    'font_weight' => '600',
                    'color'       => '#64748b',
                    'align'       => 'center',
                    'letter_spacing_px' => 2,
                    'z_index'     => 10,
                ],
                [
                    'id'          => 'elem_intern_name',
                    'type'        => 'text',
                    'content'     => '{{pemagang.nama}}',
                    'x_percent'   => 50.0,
                    'y_percent'   => 38.0,
                    'width_percent' => 85.0,
                    'font_family' => 'Times New Roman',
                    'font_size_pt'=> 30,
                    'font_weight' => 'bold',
                    'font_style'  => 'italic',
                    'color'       => '#0f172a',
                    'align'       => 'center',
                    'underline'   => true,
                    'z_index'     => 15,
                ],
                [
                    'id'          => 'elem_division_desc',
                    'type'        => 'text',
                    'content'     => 'Atas partisipasi dan dedikasinya sebagai peserta magang pada divisi {{magang.divisi}} di {{perusahaan.nama}} yang berlangsung dari tanggal {{magang.tanggal_mulai}} sampai dengan {{magang.tanggal_selesai}}.',
                    'x_percent'   => 50.0,
                    'y_percent'   => 50.0,
                    'width_percent' => 76.0,
                    'font_family' => 'Times New Roman',
                    'font_size_pt'=> 13,
                    'font_weight' => 'normal',
                    'line_height' => 1.5,
                    'color'       => '#334155',
                    'align'       => 'center',
                    'z_index'     => 10,
                ],
                [
                    'id'          => 'elem_cert_number',
                    'type'        => 'text',
                    'content'     => 'Nomor Sertifikat: {{dokumen.nomor}}',
                    'x_percent'   => 50.0,
                    'y_percent'   => 62.0,
                    'width_percent' => 50.0,
                    'font_family' => 'Courier New',
                    'font_size_pt'=> 11,
                    'font_weight' => 'bold',
                    'color'       => '#475569',
                    'align'       => 'center',
                    'z_index'     => 10,
                ],
                [
                    'id'          => 'elem_signature_block',
                    'type'        => 'signature_group',
                    'x_percent'   => 50.0,
                    'y_percent'   => 72.0,
                    'width_percent' => 45.0,
                    'date_text'   => '{{dokumen.tempat}}, {{dokumen.tanggal}}',
                    'role_text'   => '{{perusahaan.jabatan}} {{perusahaan.nama}}',
                    'name_text'   => '{{perusahaan.pimpinan}}',
                    'sig_image'   => '{{asset.signature}}',
                    'stamp_image' => '{{asset.stamp}}',
                    'align'       => 'center',
                    'z_index'     => 20,
                ],
            ],
        ];
    }

    /**
     * Snapshot revisi baru ke tabel document_template_versions.
     */
    public function createVersionSnapshot(?string $changeSummary = null, ?int $userId = null): DocumentTemplateVersion
    {
        return $this->versions()->create([
            'version_number' => $this->version_number,
            'name'           => $this->name,
            'content_html'   => $this->content_html,
            'editor_json'    => $this->editor_json,
            'canvas_layout'  => $this->canvas_layout,
            'page_config'    => $this->page_config,
            'change_summary' => $changeSummary ?? "Pembaruan versi {$this->version_number}",
            'created_by'     => $userId ?? auth()->id(),
        ]);
    }

    /**
     * Kloning template menjadi template baru.
     */
    public function duplicate(?string $newName = null, ?int $userId = null): self
    {
        $copyName = $newName ?? ($this->name . ' (Salinan)');
        $clone = static::create([
            'type'           => $this->type,
            'name'           => $copyName,
            'description'    => $this->description,
            'editor_type'    => $this->editor_type,
            'content_html'   => $this->content_html,
            'editor_json'    => $this->editor_json,
            'canvas_layout'  => $this->canvas_layout,
            'page_config'    => $this->page_config,
            'is_active'      => false, // default draft
            'is_default'     => false,
            'version_number' => 1,
            'created_by'     => $userId ?? auth()->id(),
            'updated_by'     => $userId ?? auth()->id(),
        ]);

        $clone->createVersionSnapshot('Salinan awal dari template ' . $this->name, $userId);
        return $clone;
    }
}
