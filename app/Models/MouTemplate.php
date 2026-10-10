<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MouTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mou_templates';

    protected $fillable = [
        'public_uuid',
        'name',
        'version_number',
        'template_html',
        'allowed_placeholders',
        'default_values',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'version_number'       => 'integer',
        'allowed_placeholders' => 'array',
        'default_values'       => 'array',
        'is_active'            => 'boolean',
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
     * Sanitasi ketat template HTML untuk mencegah stored XSS dan eksekusi skrip berbahaya.
     */
    public static function sanitizeTemplateHtml(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        $sanitized = $html;

        // 1. Bersihkan tag script dan isinya (berulang kali untuk menangani tag bersarang)
        do {
            $prev = $sanitized;
            $sanitized = preg_replace('/<script\b[^>]*>([\s\S]*?)<\/script>/i', '', $sanitized);
            $sanitized = preg_replace('/<script\b[^>]*\/?>/i', '', $sanitized);
        } while ($sanitized !== $prev);

        // 2. Bersihkan tag berbahaya (iframe, object, embed, applet, meta, link, base, form)
        $sanitized = preg_replace('/<\/?(iframe|object|embed|applet|meta|link|base|form)\b[^>]*>/i', '', $sanitized);

        // 3. Bersihkan inline event handler (onload, onclick, onerror, dsb.)
        $sanitized = preg_replace('/\s*on[a-zA-Z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $sanitized);

        // 4. Bersihkan URL berbahaya berprotokol javascript:, vbscript:, atau data:text/html
        $sanitized = preg_replace('/(href|src)\s*=\s*["\']\s*(?:javascript|vbscript|data):[^"\']*["\']/i', '$1="#"', $sanitized);
        $sanitized = preg_replace('/(href|src)\s*=\s*(?:javascript|vbscript|data):[^\s>]+/i', '$1="#"', $sanitized);

        return $sanitized;
    }

    /**
     * Scope untuk template yang aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * User pembuat template.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User pengubah template terakhir.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Daftar seluruh placeholder yang didukung beserta penjelasannya.
     */
    public static function getDefaultPlaceholders(): array
    {
        return [
            'institution_name'                => 'Nama Institusi / Kampus / Sekolah Mitra',
            'institution_mou_number'          => 'Nomor Surat Perjanjian Pihak Institusi',
            'institution_address'             => 'Alamat Lengkap Institusi',
            'institution_correspondence_unit' => 'Unit Korespondensi / Fakultas / Jurusan',
            'institution_phone'               => 'Nomor Telepon Institusi',
            'institution_email'               => 'Email Resmi Institusi',
            'institution_signatory_name'      => 'Nama Pejabat Penandatangan Institusi',
            'institution_signatory_title'     => 'Jabatan Penandatangan Institusi (Rektor / Dekan / Kepala Sekolah)',
            'company_name'                    => 'Nama Perusahaan (Default: SEVEN INC)',
            'company_mou_number'              => 'Nomor Surat Perjanjian Seven Inc',
            'company_address'                 => 'Alamat Kantor Seven Inc',
            'company_phone'                   => 'Nomor Telepon Seven Inc',
            'company_email'                   => 'Email Resmi Seven Inc',
            'company_signatory_name'          => 'Nama Pimpinan Penandatangan Seven Inc',
            'company_signatory_title'         => 'Jabatan Pimpinan Seven Inc (Direktur)',
            'agreement_place'                 => 'Kota / Tempat Penandatanganan',
            'agreement_date'                  => 'Tanggal Penandatanganan Dokumen',
            'effective_start'                 => 'Tanggal Mulai Berlaku Kerja Sama',
            'effective_end'                   => 'Tanggal Berakhir Masa Berlaku Kerja Sama',
        ];
    }

    /**
     * Render template HTML dengan mengganti placeholder secara aman (safe token replacement).
     */
    public function renderContent(array $data): string
    {
        $rendered = static::sanitizeTemplateHtml($this->template_html);

        // Gabungkan data input dengan default_values template
        $mergedData = array_merge($this->default_values ?? [], $data);

        foreach ($this->allowed_placeholders ?? array_keys(static::getDefaultPlaceholders()) as $rawPlaceholder) {
            $placeholder = trim($rawPlaceholder, '[]{} ');
            if (empty($placeholder)) {
                continue;
            }

            if (array_key_exists($placeholder, $mergedData)) {
                $safeValue = e((string) $mergedData[$placeholder]);
                $rendered = str_replace([
                    '[' . $placeholder . ']',
                    '{{' . $placeholder . '}}',
                    '{{ ' . $placeholder . ' }}',
                ], $safeValue, $rendered);
            }
        }

        return static::sanitizeTemplateHtml($rendered);
    }

    /**
     * Draft isi template standar baku Seven Inc x Institusi.
     */
    public static function getDefaultTemplateContent(): string
    {
        return <<<'HTML'
<div class="mou-document font-serif text-slate-900 leading-relaxed text-sm">
    <!-- Header Dokumen -->
    <div class="text-center pb-6 border-b-2 border-slate-900 mb-6">
        <h1 class="text-xl font-bold tracking-wider uppercase mb-1">SURAT PERJANJIAN KERJA SAMA (MOU)</h1>
        <p class="text-sm font-semibold uppercase tracking-wide">TENTANG PENYELENGGARAAN PROGRAM PRAKTIK KERJA LAPANGAN & MAGANG INDUSTRI</p>
        <div class="mt-3 text-xs font-mono text-slate-700">
            <p>Nomor Pihak Pertama: [institution_mou_number]</p>
            <p>Nomor Pihak Kedua: [company_mou_number]</p>
        </div>
    </div>

    <!-- Pembukaan -->
    <p class="mb-4 text-justify">
        Pada hari ini, bertempat di <strong>[agreement_place]</strong>, telah dibuat dan ditandatangani Perjanjian Kerja Sama (selanjutnya disebut "Perjanjian") oleh dan antara pihak-pihak sebagai berikut:
    </p>

    <!-- Pihak Pertama -->
    <div class="mb-4 pl-4 border-l-2 border-slate-300">
        <p class="font-bold text-slate-800">I. PIHAK KESATU (INSTITUSI MITRA):</p>
        <table class="w-full text-xs mt-1">
            <tr><td class="w-36 font-semibold py-0.5">Nama Institusi</td><td>: [institution_name]</td></tr>
            <tr><td class="font-semibold py-0.5">Unit Korespondensi</td><td>: [institution_correspondence_unit]</td></tr>
            <tr><td class="font-semibold py-0.5">Alamat</td><td>: [institution_address]</td></tr>
            <tr><td class="font-semibold py-0.5">Kontak / Email</td><td>: [institution_phone] / [institution_email]</td></tr>
            <tr><td class="font-semibold py-0.5">Diwakili Oleh</td><td>: <strong>[institution_signatory_name]</strong></td></tr>
            <tr><td class="font-semibold py-0.5">Jabatan</td><td>: [institution_signatory_title]</td></tr>
        </table>
        <p class="mt-1 text-xs text-slate-600">Dalam hal ini bertindak untuk dan atas nama institusi [institution_name], selanjutnya disebut sebagai <strong>PIHAK PERTAMA</strong>.</p>
    </div>

    <!-- Pihak Kedua -->
    <div class="mb-6 pl-4 border-l-2 border-slate-300">
        <p class="font-bold text-slate-800">II. PIHAK KEDUA (INDUSTRI MITRA):</p>
        <table class="w-full text-xs mt-1">
            <tr><td class="w-36 font-semibold py-0.5">Nama Perusahaan</td><td>: [company_name]</td></tr>
            <tr><td class="font-semibold py-0.5">Alamat</td><td>: [company_address]</td></tr>
            <tr><td class="font-semibold py-0.5">Kontak / Email</td><td>: [company_phone] / [company_email]</td></tr>
            <tr><td class="font-semibold py-0.5">Diwakili Oleh</td><td>: <strong>[company_signatory_name]</strong></td></tr>
            <tr><td class="font-semibold py-0.5">Jabatan</td><td>: [company_signatory_title]</td></tr>
        </table>
        <p class="mt-1 text-xs text-slate-600">Dalam hal ini bertindak untuk dan atas nama perusahaan [company_name], selanjutnya disebut sebagai <strong>PIHAK KEDUA</strong>.</p>
    </div>

    <p class="mb-4 text-justify">
        PIHAK PERTAMA dan PIHAK KEDUA secara bersama-sama disebut sebagai <strong>PARA PIHAK</strong>, dan masing-masing disebut sebagai <strong>PIHAK</strong>. PARA PIHAK sepakat untuk mengadakan kerja sama dengan ketentuan dan syarat-syarat yang tercantum dalam pasal-pasal berikut:
    </p>

    <!-- Pasal 1 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 1<br>MAKSUD DAN TUJUAN</h2>
        <p class="text-justify text-xs">
            Perjanjian ini bertujuan untuk menyelaraskan kompetensi pendidikan vokasi dan perguruan tinggi dengan kebutuhan industri, meningkatkan keterampilan praktis peserta didik, serta memfasilitasi pelaksanaan program Praktik Kerja Lapangan (PKL) dan Magang Industri bagi mahasiswa/peserta didik dari PIHAK PERTAMA di lingkungan unit kerja PIHAK KEDUA.
        </p>
    </div>

    <!-- Pasal 2 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 2<br>RUANG LINGKUP KERJA SAMA</h2>
        <ol class="list-decimal pl-5 text-xs space-y-1 text-justify">
            <li>Penempatan dan pelaksanaan program magang bagi peserta didik yang memenuhi kualifikasi seleksi.</li>
            <li>Bimbingan teknis, transfer ilmu, dan pengawasan kerja oleh mentor industri dari PIHAK KEDUA.</li>
            <li>Penerbitan surat penerimaan (LOA), surat keterangan selesai (SKL), sertifikat kompetensi magang, dan evaluasi penilaian akademik.</li>
            <li>Peluang kolaborasi lanjutan terkait rekrutmen kerja dan seminar/webinar pengembangan keahlian.</li>
        </ol>
    </div>

    <!-- Pasal 3 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 3<br>HAK DAN KEWAJIBAN</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs mt-2">
            <div>
                <p class="font-bold text-slate-800">Hak dan Kewajiban PIHAK PERTAMA:</p>
                <ul class="list-disc pl-4 mt-1 space-y-1 text-slate-700">
                    <li>Menyediakan data peserta magang yang sah dan teregistrasi secara akademik.</li>
                    <li>Menunjuk guru/dosen pembimbing untuk koordinasi berkala dengan PIHAK KEDUA.</li>
                    <li>Mendapatkan laporan evaluasi hasil belajar magang peserta secara transparan.</li>
                </ul>
            </div>
            <div>
                <p class="font-bold text-slate-800">Hak dan Kewajiban PIHAK KEDUA:</p>
                <ul class="list-disc pl-4 mt-1 space-y-1 text-slate-700">
                    <li>Menyediakan lingkungan kerja yang kondusif, tugas kerja yang relevan, serta mentor pendamping.</li>
                    <li>Berhak memberlakukan tata tertib kerja industri kepada seluruh peserta magang.</li>
                    <li>Menerbitkan sertifikat dan lembar penilaian magang resmi bagi peserta yang menuntaskan program.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Pasal 4 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 4<br>JANGKA WAKTU PERJANJIAN</h2>
        <p class="text-justify text-xs">
            Perjanjian Kerja Sama ini berlaku terhitung sejak tanggal <strong>[effective_start]</strong> sampai dengan tanggal <strong>[effective_end]</strong>. Perjanjian ini dapat diperpanjang, diubah, atau diakhiri atas kesepakatan tertulis dari PARA PIHAK selambat-lambatnya 30 (tiga puluh) hari kalender sebelum masa berlaku berakhir.
        </p>
    </div>

    <!-- Pasal 5 -->
    <div class="mb-6">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 5<br>PENUTUP</h2>
        <p class="text-justify text-xs">
            Demikian Perjanjian Kerja Sama ini dibuat dalam rangkap 2 (dua) bermeterai cukup dan memiliki kekuatan hukum yang sama bagi masing-masing pihak setelah ditandatangani oleh wakil yang sah dari PARA PIHAK pada tanggal <strong>[agreement_date]</strong>.
        </p>
    </div>

    <!-- Kolom Tanda Tangan -->
    <div class="grid grid-cols-2 gap-8 pt-6 border-t border-slate-200 mt-8 text-center text-xs">
        <div>
            <p class="font-semibold text-slate-600">PIHAK PERTAMA</p>
            <p class="font-bold text-slate-800">[institution_name]</p>
            <div class="h-20 flex items-center justify-center text-slate-300 italic text-[11px]">
                (Tanda Tangan & Cap Institusi)
            </div>
            <p class="font-bold text-slate-900 underline">[institution_signatory_name]</p>
            <p class="text-[11px] text-slate-600">[institution_signatory_title]</p>
        </div>
        <div>
            <p class="font-semibold text-slate-600">PIHAK KEDUA</p>
            <p class="font-bold text-slate-800">[company_name]</p>
            <div class="h-20 flex items-center justify-center text-slate-300 italic text-[11px]">
                (Tanda Tangan & Cap Perusahaan)
            </div>
            <p class="font-bold text-slate-900 underline">[company_signatory_name]</p>
            <p class="text-[11px] text-slate-600">[company_signatory_title]</p>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Naskah template Nota Kesepahaman Tri Dharma Perguruan Tinggi & Pengembangan SDM.
     */
    public static function getTriDharmaTemplateContent(): string
    {
        return <<<'HTML'
<div class="mou-document font-serif text-slate-900 leading-relaxed text-sm">
    <!-- Header Dokumen -->
    <div class="text-center pb-6 border-b-2 border-slate-900 mb-6">
        <h1 class="text-xl font-bold tracking-wider uppercase mb-1">NOTA KESEPAHAMAN (MEMORANDUM OF UNDERSTANDING)</h1>
        <p class="text-sm font-semibold uppercase tracking-wide">ANTARA</p>
        <p class="text-base font-bold uppercase tracking-wide text-slate-800">[institution_name]</p>
        <p class="text-sm font-semibold uppercase tracking-wide">DENGAN</p>
        <p class="text-base font-bold uppercase tracking-wide text-slate-800">[company_name]</p>
        <p class="text-xs font-semibold uppercase tracking-wide mt-2 text-slate-700">TENTANG PENYELENGGARAAN TRI DHARMA PERGURUAN TINGGI, MAGANG KERJA INDUSTRI, DAN PENGEMBANGAN SUMBER DAYA MANUSIA</p>
        <div class="mt-3 text-xs font-mono text-slate-700">
            <p>Nomor Pihak Pertama: [institution_mou_number]</p>
            <p>Nomor Pihak Kedua: [company_mou_number]</p>
        </div>
    </div>

    <!-- Pembukaan -->
    <p class="mb-4 text-justify">
        Pada hari ini, bertempat di <strong>[agreement_place]</strong>, telah dibuat dan ditandatangani Nota Kesepahaman (selanjutnya disebut "Nota Kesepahaman") oleh dan antara pihak-pihak:
    </p>

    <!-- Pihak Pertama -->
    <div class="mb-4 pl-4 border-l-2 border-slate-300">
        <p class="font-bold text-slate-800">I. PIHAK KESATU (INSTITUSI PENDIDIKAN TINGGI):</p>
        <table class="w-full text-xs mt-1">
            <tr><td class="w-36 font-semibold py-0.5">Nama Institusi</td><td>: [institution_name]</td></tr>
            <tr><td class="font-semibold py-0.5">Unit Korespondensi</td><td>: [institution_correspondence_unit]</td></tr>
            <tr><td class="font-semibold py-0.5">Alamat</td><td>: [institution_address]</td></tr>
            <tr><td class="font-semibold py-0.5">Kontak / Email</td><td>: [institution_phone] / [institution_email]</td></tr>
            <tr><td class="font-semibold py-0.5">Diwakili Oleh</td><td>: <strong>[institution_signatory_name]</strong></td></tr>
            <tr><td class="font-semibold py-0.5">Jabatan</td><td>: [institution_signatory_title]</td></tr>
        </table>
        <p class="mt-1 text-xs text-slate-600">Dalam hal ini bertindak untuk dan atas nama institusi [institution_name], selanjutnya disebut sebagai <strong>PIHAK KESATU</strong>.</p>
    </div>

    <!-- Pihak Kedua -->
    <div class="mb-6 pl-4 border-l-2 border-slate-300">
        <p class="font-bold text-slate-800">II. PIHAK KEDUA (DUNIA USAHA & DUNIA INDUSTRI):</p>
        <table class="w-full text-xs mt-1">
            <tr><td class="w-36 font-semibold py-0.5">Nama Perusahaan</td><td>: [company_name]</td></tr>
            <tr><td class="font-semibold py-0.5">Alamat</td><td>: [company_address]</td></tr>
            <tr><td class="font-semibold py-0.5">Kontak / Email</td><td>: [company_phone] / [company_email]</td></tr>
            <tr><td class="font-semibold py-0.5">Diwakili Oleh</td><td>: <strong>[company_signatory_name]</strong></td></tr>
            <tr><td class="font-semibold py-0.5">Jabatan</td><td>: [company_signatory_title]</td></tr>
        </table>
        <p class="mt-1 text-xs text-slate-600">Dalam hal ini bertindak untuk dan atas nama perusahaan [company_name], selanjutnya disebut sebagai <strong>PIHAK KEDUA</strong>.</p>
    </div>

    <p class="mb-4 text-justify">
        PIHAK KESATU dan PIHAK KEDUA secara bersama-sama disebut sebagai <strong>PARA PIHAK</strong>, dan masing-masing disebut sebagai <strong>PIHAK</strong>. PARA PIHAK sepakat untuk mengadakan kerja sama kemitraan strategis dengan ketentuan sebagaimana tercantum dalam pasal-pasal berikut:
    </p>

    <!-- Pasal 1 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 1<br>MAKSUD DAN TUJUAN</h2>
        <p class="text-justify text-xs">
            Nota Kesepahaman ini dimaksudkan sebagai landasan kerja sama kemitraan strategis antara PARA PIHAK dalam rangka menyinergikan potensi masing-masing pihak guna meningkatkan kualitas penyelenggaraan Tri Dharma Perguruan Tinggi (pendidikan, penelitian, dan pengabdian kepada masyarakat), serta penyelarasan kompetensi sumber daya manusia dengan kebutuhan dunia kerja dan industri digital.
        </p>
    </div>

    <!-- Pasal 2 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 2<br>RUANG LINGKUP KERJA SAMA</h2>
        <ol class="list-decimal pl-5 text-xs space-y-1 text-justify">
            <li>Penyelenggaraan program Praktik Kerja Lapangan (PKL), Magang Industri Bersertifikat, dan riset terapan bagi mahasiswa PIHAK KESATU di unit kerja PIHAK KEDUA.</li>
            <li>Penyelenggaraan kegiatan dosen/praktisi industri tamu, seminar keilmuan, lokakarya, dan pengembangan kurikulum berbasis kebutuhan industri teknologi terkini.</li>
            <li>Kolaborasi riset, pengembangan inovasi produk digital, serta program pengabdian kepada masyarakat bersama.</li>
            <li>Penyaluran dan rekrutmen talenta lulusan berprestasi dari PIHAK KESATU pada posisi kerja yang relevan di lingkungan PIHAK KEDUA.</li>
        </ol>
    </div>

    <!-- Pasal 3 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 3<br>PELAKSANAAN DAN PERJANJIAN TURUNAN</h2>
        <p class="text-justify text-xs">
            Hal-hal teknis mengenai operasional, hak dan kewajiban terperinci, jadwal kegiatan, serta pembiayaan dari masing-masing program kerja sama akan diatur lebih lanjut dalam Perjanjian Kerja Sama (PKS) atau dokumen operasional turunan yang ditandatangani oleh unit pelaksana teknis yang berwenang dari PARA PIHAK.
        </p>
    </div>

    <!-- Pasal 4 -->
    <div class="mb-4">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 4<br>JANGKA WAKTU</h2>
        <p class="text-justify text-xs">
            Nota Kesepahaman ini berlaku untuk jangka waktu terhitung sejak tanggal <strong>[effective_start]</strong> sampai dengan tanggal <strong>[effective_end]</strong>, dan dapat diperpanjang atau diubah sewaktu-waktu berdasarkan kesepakatan tertulis dari PARA PIHAK selambat-lambatnya 30 (tiga puluh) hari kalender sebelum masa berlaku berakhir.
        </p>
    </div>

    <!-- Pasal 5 -->
    <div class="mb-6">
        <h2 class="font-bold text-center text-xs uppercase mb-1">PASAL 5<br>PENUTUP</h2>
        <p class="text-justify text-xs">
            Demikian Nota Kesepahaman ini dibuat dalam rangkap 2 (dua) asli bermeterai cukup dan memiliki kekuatan hukum yang sama bagi masing-masing pihak setelah ditandatangani oleh pimpinan yang sah dari PARA PIHAK pada tanggal <strong>[agreement_date]</strong>.
        </p>
    </div>

    <!-- Kolom Tanda Tangan -->
    <div class="grid grid-cols-2 gap-8 pt-6 border-t border-slate-200 mt-8 text-center text-xs">
        <div>
            <p class="font-semibold text-slate-600">PIHAK KESATU</p>
            <p class="font-bold text-slate-800">[institution_name]</p>
            <div class="h-20 flex items-center justify-center text-slate-300 italic text-[11px]">
                (Tanda Tangan & Cap Institusi)
            </div>
            <p class="font-bold text-slate-900 underline">[institution_signatory_name]</p>
            <p class="text-[11px] text-slate-600">[institution_signatory_title]</p>
        </div>
        <div>
            <p class="font-semibold text-slate-600">PIHAK KEDUA</p>
            <p class="font-bold text-slate-800">[company_name]</p>
            <div class="h-20 flex items-center justify-center text-slate-300 italic text-[11px]">
                (Tanda Tangan & Cap Perusahaan)
            </div>
            <p class="font-bold text-slate-900 underline">[company_signatory_name]</p>
            <p class="text-[11px] text-slate-600">[company_signatory_title]</p>
        </div>
    </div>
</div>
HTML;
    }
}
