<?php

namespace App\Services\MailMerge;

use App\Models\Brand;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use App\Models\SystemAsset;
use App\Services\SystemAssetService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class VariableResolverService
{
    public function __construct(
        protected SystemAssetService $assetService
    ) {}

    /**
     * Selesaikan seluruh variabel teks dan aset dari konteks dokumen dengan keamanan owner isolation (INV-05).
     *
     * @return DocumentContext Typed DTO yang juga mengimplementasikan ArrayAccess untuk kompatibilitas mundur.
     */
    public function resolveContext(
        ?InternshipRegistration $intern = null,
        ?Institution $institution = null,
        ?Brand $brand = null,
        ?Mou $mou = null,
        array $overrides = [],
        array $assetOverrides = [],
        bool $isLiveIssuance = false,
        bool $isPreviewMock = false,
        ?string $documentType = null
    ): DocumentContext {
        $variables = [];
        $assetReferences = [];
        $resolvedImages = [];
        $missingAssets = [];

        // 1. Ekstraksi Model Brand Terpilih
        $brandModel = $brand;
        if (!$brandModel && $intern && $intern->brandRel) {
            $brandModel = $intern->brandRel;
        }

        // 2. Ekstraksi Model Institusi Terpilih
        $instModel = $institution;
        if (!$instModel && $intern && $intern->institution) {
            $instModel = $intern->institution;
        }

        // ==================== RESOLUSI DATA PEMAGANG ====================
        if ($intern) {
            $variables['pemagang.nama'] = $intern->fullname ?? ($isPreviewMock ? 'Ahmad Zaki Fauzi' : '');
            $variables['pemagang.nim'] = ($intern->student_id ?? $intern->nim_nis ?? $intern->nim ?? '-') ?: '-';
            $variables['pemagang.institusi'] = $intern->institution_name ?? ($instModel?->name ?? ($isPreviewMock ? 'Universitas Gadjah Mada' : '-'));
            $variables['pemagang.prodi'] = $intern->study_program ?? ($intern->major ?? '-');
            $variables['pemagang.divisi'] = $intern->division?->name ?? ($intern->internship_interest ?? 'Umum');
            $variables['pemagang.brand'] = $brandModel?->name ?? ($intern->brand ?? 'Seven Inc');
            $variables['pemagang.kontak'] = $intern->phone_number ?? ($intern->email ?? '-');

            $startDateStr = $intern->start_date ? Carbon::parse($intern->start_date)->locale('id')->translatedFormat('d F Y') : '-';
            $endDateStr = $intern->end_date ? Carbon::parse($intern->end_date)->locale('id')->translatedFormat('d F Y') : '-';
            $variables['pemagang.tanggal_mulai'] = $startDateStr;
            $variables['pemagang.tanggal_selesai'] = $endDateStr;
            $variables['pemagang.periode'] = ($startDateStr !== '-' && $endDateStr !== '-')
                ? "{$startDateStr} - {$endDateStr}"
                : '-';

            $durationStr = '6 bulan';
            if ($intern->start_date && $intern->end_date) {
                $days = Carbon::parse($intern->start_date)->diffInDays(Carbon::parse($intern->end_date));
                $months = max(1, (int) round($days / 30));
                $durationStr = "{$months} bulan";
            }
            $variables['pemagang.durasi'] = $durationStr;
            $variables['magang.divisi'] = $variables['pemagang.divisi'];
            $variables['magang.tanggal_mulai'] = $variables['pemagang.tanggal_mulai'];
            $variables['magang.tanggal_selesai'] = $variables['pemagang.tanggal_selesai'];
            $variables['magang.durasi'] = $variables['pemagang.durasi'];
        }

        // ==================== RESOLUSI DATA INSTITUSI MITRA ====================
        if ($instModel) {
            $variables['institusi.nama'] = $instModel->name;
            $variables['institusi.alamat'] = $instModel->address ?? '-';
            $variables['institusi.telepon'] = $instModel->phone ?? '-';
            $variables['institusi.email'] = $instModel->email ?? '-';
            $variables['institusi.fax'] = $instModel->fax ?? '-';

            // Memperbaiki kolom position_title dari institution_contacts (bukan position)
            $firstContact = $instModel->contacts()->where('is_active', true)->first() ?: $instModel->contacts()->first();
            $contactName = $firstContact?->name ?? '-';
            $contactTitle = $firstContact?->position_title ?? ($firstContact?->position ?? '-');

            $variables['institusi.kontak_nama'] = $contactName;
            $variables['institusi.kontak_jabatan'] = $contactTitle;
            $variables['institusi.penandatangan.nama'] = $contactName;
            $variables['institusi.penandatangan.jabatan'] = $contactTitle;
            $variables['institusi.unit_korespondensi'] = $instModel->correspondence_unit ?? '-';
        }

        // ==================== RESOLUSI DATA DOKUMEN / MOU ====================
        if ($mou) {
            $variables['dokumen.nomor'] = $mou->company_mou_number;
            $variables['dokumen.tanggal'] = $mou->agreement_date ? Carbon::parse($mou->agreement_date)->translatedFormat('d F Y') : now()->translatedFormat('d F Y');
            $variables['dokumen.tempat'] = $mou->agreement_place ?? 'Yogyakarta';
            $variables['dokumen.penandatangan_nama'] = $mou->second_party_signatory_name ?? ($brandModel?->signatory_name ?? ($isLiveIssuance ? null : 'Rekario Danny Sanjaya, S.Kom.'));
            $variables['dokumen.penandatangan_jabatan'] = $mou->second_party_signatory_title ?? ($brandModel?->signatory_position ?? ($isLiveIssuance ? null : 'Pimpinan'));
            $variables['brand.nama'] = $mou->second_party_company_name ?? ($brandModel?->name ?? 'SEVEN INC');
            $variables['brand.alamat'] = $mou->second_party_address ?? ($brandModel?->address ?? '-');
            $variables['brand.telepon'] = $mou->second_party_phone ?? ($brandModel?->phone ?? '-');
            $variables['brand.email'] = $mou->second_party_email ?? ($brandModel?->email ?? '-');
            $variables['brand.penandatangan.nama'] = $variables['dokumen.penandatangan_nama'];
            $variables['brand.penandatangan.jabatan'] = $variables['dokumen.penandatangan_jabatan'];
            $variables['brand.nomor_mou'] = $mou->company_mou_number;

            $variables['institusi.nama'] = $mou->first_party_institution_name ?? ($instModel?->name ?? 'Institusi Mitra');
            $variables['institusi.alamat'] = $mou->first_party_address ?? ($instModel?->address ?? '-');
            $variables['institusi.telepon'] = $mou->first_party_phone ?? ($instModel?->phone ?? '-');
            $variables['institusi.email'] = $mou->first_party_email ?? ($instModel?->email ?? '-');
            $variables['institusi.penandatangan.nama'] = $mou->first_party_signatory_name ?? '-';
            $variables['institusi.penandatangan.jabatan'] = $mou->first_party_signatory_title ?? '-';
            $variables['institusi.nomor_mou'] = $mou->institution_mou_number ?? '-';
            $variables['institusi.unit_korespondensi'] = $mou->first_party_correspondence_unit ?? '-';

            $variables['mou.tanggal_mulai'] = $mou->effective_start ? Carbon::parse($mou->effective_start)->translatedFormat('d F Y') : '-';
            $variables['mou.tanggal_selesai'] = $mou->effective_end ? Carbon::parse($mou->effective_end)->translatedFormat('d F Y') : '-';
            $variables['mou.durasi'] = ($mou->effective_start && $mou->effective_end)
                ? (Carbon::parse($mou->effective_start)->diffInYears(Carbon::parse($mou->effective_end)) . ' tahun')
                : '-';
        } else {
            $variables['dokumen.tanggal'] = now()->translatedFormat('d F Y');
            $variables['dokumen.tempat'] = $brandModel?->city ?? 'Sleman';
            $variables['dokumen.penandatangan_nama'] = $brandModel?->signatory_name ?? ($isLiveIssuance ? null : 'Rekario Danny Sanjaya, S.Kom.');
            $variables['dokumen.penandatangan_jabatan'] = $brandModel?->signatory_position ?? ($isLiveIssuance ? null : 'Direktur Utama');

            $variables['brand.nama'] = $brandModel?->name ?? 'SEVEN INC';
            $variables['brand.alamat'] = $brandModel?->company_address ?? ($brandModel?->address ?? 'Jl. Janti Gg. Arjuna No. 59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198');
            $variables['brand.telepon'] = $brandModel?->phone ?? '-';
            $variables['brand.email'] = $brandModel?->email ?? '-';
            $variables['brand.penandatangan.nama'] = $variables['dokumen.penandatangan_nama'];
            $variables['brand.penandatangan.jabatan'] = $variables['dokumen.penandatangan_jabatan'];
        }

        // Terapkan overrides eksplisit jika ada
        foreach ($overrides as $k => $v) {
            $variables[$k] = $v;
        }

        // Sinkronisasi alias dua arah untuk data teks
        $aliasesMap = VariableRegistry::getAliasesMap();
        foreach ($variables as $key => $val) {
            $lower = strtolower($key);
            $canonical = $aliasesMap[$lower] ?? $key;
            if (!isset($variables[$canonical])) {
                $variables[$canonical] = $val;
            }
        }
        // Sinkronisasi alias berdasarkan konteks jenis dokumen (V01)
        $aliasesMap = VariableRegistry::getAliasesMap($documentType);
        foreach ($aliasesMap as $alias => $canonicalKey) {
            if (isset($variables[$canonicalKey]) && !isset($variables[$alias])) {
                $variables[$alias] = $variables[$canonicalKey];
            }
        }
        foreach (VariableRegistry::getDefinitions() as $canonicalKey => $def) {
            if (isset($variables[$canonicalKey])) {
                $val = $variables[$canonicalKey];
                foreach ($def['aliases'] ?? [] as $alias) {
                    if (!isset($variables[$alias])) {
                        $variables[$alias] = $val;
                    }
                }
            }
        }

        // ==================== RESOLUSI ASET VISUAL (OWNER-ISOLATED) ====================
        $assetTokens = [
            'asset.brand.logo'            => ['category' => SystemAsset::CATEGORY_LOGO,       'owner' => 'brand'],
            'asset.company.logo'          => ['category' => SystemAsset::CATEGORY_LOGO,       'owner' => 'brand'],
            'asset.institusi.logo'        => ['category' => SystemAsset::CATEGORY_LOGO,       'owner' => 'institution'],
            'asset.institution.logo'      => ['category' => SystemAsset::CATEGORY_LOGO,       'owner' => 'institution'],
            'asset.brand.tanda_tangan'    => ['category' => SystemAsset::CATEGORY_SIGNATURE,  'owner' => 'brand'],
            'asset.institusi.tanda_tangan'=> ['category' => SystemAsset::CATEGORY_SIGNATURE,  'owner' => 'institution'],
            'asset.brand.stempel'         => ['category' => SystemAsset::CATEGORY_STAMP,      'owner' => 'brand'],
            'asset.institusi.stempel'     => ['category' => SystemAsset::CATEGORY_STAMP,      'owner' => 'institution'],
            'asset.signature'             => ['category' => SystemAsset::CATEGORY_SIGNATURE,  'owner' => 'brand'],
            'asset.stamp'                 => ['category' => SystemAsset::CATEGORY_STAMP,      'owner' => 'brand'],
            'asset.letterhead'            => ['category' => SystemAsset::CATEGORY_LETTERHEAD, 'owner' => 'brand'],
            'asset.background'            => ['category' => SystemAsset::CATEGORY_BACKGROUND, 'owner' => 'global'],
        ];

        $brandId = $brandModel?->id;
        $instId = $instModel?->id;

        foreach ($assetTokens as $tokenKey => $meta) {
            $category = $meta['category'];
            $owner = $meta['owner'];
            $resolvedAsset = null;

            // 1. Cek explicit override ID, kode aset, atau model objek (V02)
            if (!empty($assetOverrides[$tokenKey])) {
                $overrideVal = $assetOverrides[$tokenKey];
                $overrideAsset = null;

                if ($overrideVal instanceof SystemAsset) {
                    $overrideAsset = $overrideVal;
                } elseif (is_numeric($overrideVal)) {
                    $overrideAsset = SystemAsset::active()->whereNull('archived_at')->find((int) $overrideVal);
                } elseif (is_string($overrideVal)) {
                    $overrideAsset = SystemAsset::active()->whereNull('archived_at')->where('asset_code', $overrideVal)->first();
                }

                // Guard Keamanan Aset (V02 & INV-05):
                // Validasi: kategori wajib cocok, status aktif, tidak diarsip, dan isolasi kepemilikan ketat
                if ($overrideAsset) {
                    $isCategoryMatch = ($overrideAsset->category === $category);
                    $isActive = ($overrideAsset->is_active && $overrideAsset->archived_at === null);

                    if ($isCategoryMatch && $isActive) {
                        if ($owner === 'institution') {
                            // Institusi: HANYA milik institusi pemohon (bukan institusi lain, brand lain, atau global)
                            if ($instId !== null && (int) $overrideAsset->institution_id === (int) $instId && $overrideAsset->scope === SystemAsset::SCOPE_INSTITUTION) {
                                $resolvedAsset = $overrideAsset;
                            }
                        } elseif ($owner === 'brand') {
                            // Tanda tangan & stempel: DILARANG KERAS scope global! Wajib scope brand dan brand_id cocok
                            if (in_array($category, [SystemAsset::CATEGORY_SIGNATURE, SystemAsset::CATEGORY_STAMP], true)) {
                                if ($brandId !== null && (int) $overrideAsset->brand_id === (int) $brandId && $overrideAsset->scope === SystemAsset::SCOPE_BRAND) {
                                    $resolvedAsset = $overrideAsset;
                                }
                            } else {
                                // Logo / kop surat: diizinkan jika milik brand terkait ATAU scope global
                                if (($brandId !== null && (int) $overrideAsset->brand_id === (int) $brandId && $overrideAsset->scope === SystemAsset::SCOPE_BRAND)
                                    || $overrideAsset->scope === SystemAsset::SCOPE_GLOBAL) {
                                    $resolvedAsset = $overrideAsset;
                                }
                            }
                        } elseif ($owner === 'global') {
                            if ($overrideAsset->scope === SystemAsset::SCOPE_GLOBAL) {
                                $resolvedAsset = $overrideAsset;
                            }
                        }
                    }
                }
            }

            // 2. Resolusi aset via SystemAssetService dengan owner filtering ketat
            if (!$resolvedAsset) {
                if ($owner === 'institution') {
                    $resolvedAsset = $this->assetService->resolveAsset(
                        category: $category,
                        assetCode: $tokenKey,
                        brandId: null,
                        institutionId: $instId,
                        allowGlobalFallback: false // Institusi dilarang fallback ke brand/global!
                    );
                } elseif ($owner === 'brand') {
                    // Addendum Final V4: Jika brand_id ditentukan, dilarang keras fallback ke logo Seven Inc global
                    $resolvedAsset = $this->assetService->resolveAsset(
                        category: $category,
                        assetCode: $tokenKey,
                        brandId: $brandId,
                        institutionId: null,
                        allowGlobalFallback: ($brandId === null)
                    );
                } else {
                    // Global (cth: background sertifikat)
                    $resolvedAsset = $this->assetService->resolveAsset(
                        category: $category,
                        assetCode: $tokenKey,
                        brandId: $brandId,
                        institutionId: null,
                        allowGlobalFallback: true
                    );
                }
            }

            if ($resolvedAsset) {
                $assetReferences[$tokenKey] = [
                    'asset_id'   => $resolvedAsset->id,
                    'name'       => $resolvedAsset->name,
                    'version'    => $resolvedAsset->version,
                    'checksum'   => $resolvedAsset->checksum_sha256,
                    'category'   => $resolvedAsset->category,
                    'scope'      => $resolvedAsset->scope,
                    'owner_type' => $owner,
                ];

                $dataUri = $this->assetService->getAssetAsBase64DataUri($resolvedAsset);
                $resolvedImages[$tokenKey] = $dataUri;
                $variables[$tokenKey] = $dataUri;
            } else {
                $missingAssets[] = $tokenKey;

                // Fallback HANYA untuk preview mock ketika brand belum ditentukan secara spesifik
                // DILARANG KERAS untuk live issuance resmi atau fallback lintas-owner brand (Addendum V4 C14, C15)
                if ($isPreviewMock && !$isLiveIssuance) {
                    $fallback = match ($category) {
                        SystemAsset::CATEGORY_LOGO       => ($owner === 'institution')
                            ? 'images/mou/institution-logo-placeholder.png'
                            : ($brandId ? null : 'images/logos/logo_seveninc.png'),
                        SystemAsset::CATEGORY_SIGNATURE  => ($brandId ? null : 'images/signatures/ttd.png'),
                        SystemAsset::CATEGORY_STAMP      => ($brandId ? null : 'images/stamps/stamp.png'),
                        SystemAsset::CATEGORY_BACKGROUND => 'images/backgrounds/cert_bg.png',
                        default                          => null,
                    };
                    $dataUri = $fallback ? $this->assetService->getAssetAsBase64DataUri(null, $fallback) : null;
                    $resolvedImages[$tokenKey] = $dataUri;
                    $variables[$tokenKey] = $dataUri;
                } else {
                    // Penerbitan resmi: tidak ada tanda tangan/stempel/logo palsu
                    $resolvedImages[$tokenKey] = null;
                    $variables[$tokenKey] = null;
                }
            }
        }

        // Sinkronisasi alias untuk aset
        if (isset($variables['asset.brand.logo'])) {
            $variables['asset.company.logo'] = $variables['asset.brand.logo'];
        }
        if (isset($variables['asset.institusi.logo'])) {
            $variables['asset.institution.logo'] = $variables['asset.institusi.logo'];
        }

        return new DocumentContext(
            documentType: $documentType ?? 'general',
            variables: $variables,
            assetReferences: $assetReferences,
            resolvedImages: $resolvedImages,
            missingAssets: $missingAssets,
            documentNumber: $variables['dokumen.nomor'] ?? ($variables['mou.nomor_mou'] ?? null),
            internshipRegistrationId: $intern?->id,
            institutionId: $instId,
            brandId: $brandId,
            mouId: $mou?->id,
            isLiveIssuance: $isLiveIssuance,
            isPreviewMock: $isPreviewMock
        );
    }

    /**
     * Diagnosis variabel yang belum terpenuhi pada dokumen serta deteksi placeholder tidak dikenal (FG-02).
     */
    public function diagnoseMissingVariables(array $usedTokens, array $resolvedVariables, string $documentType): array
    {
        $requiredTokens = VariableRegistry::getRequiredTokens($documentType);
        $missingRequired = [];
        $unknownTokens = [];
        $warnings = [];

        foreach ($usedTokens as $token) {
            $rawClean = trim($token);
            $rawClean = preg_replace('/^(\{\{|\{|\[)\s*|\s*(\}\}|\}|\])$/', '', $rawClean);
            $canonical = VariableRegistry::normalizeToken($token, $documentType);
            $def = VariableRegistry::getDefinition($canonical, $documentType);

            // Deteksi placeholder tidak dikenal / salah ketik (FG-02)
            if (!$def && !isset($resolvedVariables[$canonical]) && !isset($resolvedVariables[$token]) && !isset($resolvedVariables[$rawClean])) {
                $unknownTokens[] = $token;
                continue;
            }

            $val = $resolvedVariables[$canonical] ?? ($resolvedVariables[$token] ?? ($resolvedVariables[$rawClean] ?? null));
            $isEmpty = ($val === null || trim((string) $val) === '' || trim((string) $val) === '-');

            if ($def && in_array($canonical, $requiredTokens, true) && $isEmpty) {
                $missingRequired[$canonical] = [
                    'token' => $token,
                    'canonical' => $canonical,
                    'label' => $def['label'],
                    'category' => $def['category'],
                    'source' => $def['source'],
                ];
            } elseif ($isEmpty) {
                $warnings[$canonical] = [
                    'token' => $token,
                    'canonical' => $canonical,
                    'label' => $def['label'] ?? $token,
                ];
            }
        }

        $uniqueUnknown = array_values(array_unique($unknownTokens));

        return [
            'valid'            => empty($missingRequired) && empty($uniqueUnknown),
            'missing_required' => $missingRequired,
            'unknown_tokens'   => $uniqueUnknown,
            'warnings'         => $warnings,
        ];
    }

    /**
     * Gantikan token placeholder di dalam konten teks atau HTML dengan nilai yang telah diselesaikan.
     * Menggunakan scanner berbasis konteks jenis dokumen (V01) untuk menangkal collision alias antar entitas.
     */
    public function renderContent(
        string $templateContent,
        array|DocumentContext $resolvedContext,
        bool $isLiveIssuance = false,
        ?string $documentType = null
    ): string {
        $variables = $resolvedContext['variables'] ?? [];
        $rendered = $templateContent;

        // 1. Scan dan ganti seluruh token {{token}} dan [token] menggunakan canonical registry dengan context-aware disambiguation
        $pattern = '/(\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}|\[\s*([a-zA-Z0-9_\.]+)\s*\])/';
        $rendered = preg_replace_callback($pattern, function ($matches) use ($variables, $isLiveIssuance, $documentType) {
            $rawToken = !empty($matches[2]) ? $matches[2] : ($matches[3] ?? '');
            if ($rawToken === '') {
                return $matches[0];
            }

            $canonical = VariableRegistry::normalizeToken($rawToken, $documentType);
            $def = VariableRegistry::getDefinition($canonical, $documentType);

            // Jika token tidak ada di $variables dan tidak ada di registry sama sekali (token asing/unknown), biarkan utuh
            if (!isset($variables[$canonical]) && !isset($variables[$rawToken]) && !$def) {
                return $matches[0];
            }

            $val = $variables[$canonical] ?? ($variables[$rawToken] ?? null);

            if ($val === null) {
                if ($isLiveIssuance) {
                    $val = '';
                } else {
                    $val = $def['sample'] ?? '';
                }
            }

            return (string) $val;
        }, $rendered);

        return $rendered;
    }
}
