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
     * Selesaikan seluruh variabel teks dan aset dari konteks dokumen.
     *
     * @return array [ 'variables' => array, 'asset_references' => array, 'resolved_images' => array ]
     */
    public function resolveContext(
        ?InternshipRegistration $intern = null,
        ?Institution $institution = null,
        ?Brand $brand = null,
        ?Mou $mou = null,
        array $overrides = [],
        array $assetOverrides = []
    ): array {
        $variables = [];
        $assetReferences = [];
        $resolvedImages = [];

        // 1. Ekstraksi Data Brand Terkait
        $brandModel = $brand;
        if (!$brandModel && $intern && $intern->brandRel) {
            $brandModel = $intern->brandRel;
        }

        // 2. Ekstraksi Data Institusi Terkait
        $instModel = $institution;
        if (!$instModel && $intern && $intern->institution) {
            $instModel = $intern->institution;
        }

        // ==================== RESOLUSI DATA PEMAGANG ====================
        if ($intern) {
            $variables['pemagang.nama'] = $intern->fullname ?? 'Nama Pemagang';
            $variables['pemagang.nim'] = ($intern->student_id ?? $intern->nim_nis ?? $intern->nim ?? '-') ?: '-';
            $variables['pemagang.institusi'] = $intern->institution_name ?? ($instModel?->name ?? '-');
            $variables['pemagang.prodi'] = $intern->study_program ?? ($intern->major ?? '-');
            $variables['pemagang.divisi'] = $intern->division?->name ?? ($intern->internship_interest ?? 'Umum');
            $variables['pemagang.brand'] = $brandModel?->name ?? ($intern->brand ?? 'Seven Inc');
            $variables['pemagang.kontak'] = $intern->phone_number ?? ($intern->email ?? '-');

            $startDateStr = $intern->start_date ? Carbon::parse($intern->start_date)->translatedFormat('d F Y') : '-';
            $endDateStr = $intern->end_date ? Carbon::parse($intern->end_date)->translatedFormat('d F Y') : '-';
            $variables['pemagang.tanggal_mulai'] = $startDateStr;
            $variables['pemagang.tanggal_selesai'] = $endDateStr;
            $variables['pemagang.periode'] = ($startDateStr !== '-' && $endDateStr !== '-')
                ? "{$startDateStr} - {$endDateStr}"
                : '-';
        }

        // ==================== RESOLUSI DATA INSTITUSI ====================
        if ($instModel) {
            $variables['institusi.nama'] = $instModel->name;
            $variables['institusi.alamat'] = $instModel->address ?? '-';
            $variables['institusi.telepon'] = $instModel->phone ?? '-';
            $variables['institusi.email'] = $instModel->email ?? '-';

            $firstContact = $instModel->contacts()->first();
            $variables['institusi.kontak_nama'] = $firstContact?->name ?? '-';
            $variables['institusi.kontak_jabatan'] = $firstContact?->position ?? '-';
        }

        // ==================== RESOLUSI DATA DOKUMEN / MOU ====================
        if ($mou) {
            $variables['dokumen.nomor'] = $mou->company_mou_number;
            $variables['dokumen.tanggal'] = $mou->agreement_date ? Carbon::parse($mou->agreement_date)->translatedFormat('d F Y') : now()->translatedFormat('d F Y');
            $variables['dokumen.penandatangan_nama'] = $mou->second_party_signatory_name ?? 'Rekario Danny Sanjaya, S.Kom.';
            $variables['dokumen.penandatangan_jabatan'] = $mou->second_party_signatory_title ?? 'Pimpinan';
            $variables['perusahaan.nama'] = $mou->second_party_company_name ?? 'SEVEN INC';
            $variables['company_name'] = $variables['perusahaan.nama'];
            $variables['institution_name'] = $mou->first_party_institution_name ?? ($instModel?->name ?? 'Institusi Mitra');
            $variables['institusi.nama'] = $variables['institution_name'];
        } else {
            $variables['dokumen.tanggal'] = now()->translatedFormat('d F Y');
            $variables['dokumen.penandatangan_nama'] = $brandModel?->signatory_name ?? 'Rekario Danny Sanjaya, S.Kom.';
            $variables['dokumen.penandatangan_jabatan'] = $brandModel?->signatory_position ?? 'Pimpinan';
            $variables['perusahaan.nama'] = $brandModel?->name ?? 'SEVEN INC';
            $variables['company_name'] = $variables['perusahaan.nama'];
        }

        // Terapkan overrides eksplisit jika ada
        foreach ($overrides as $k => $v) {
            $variables[$k] = $v;
        }

        // ==================== RESOLUSI ASET VISUAL (HIERARKI TERARAH) ====================
        $assetCategories = [
            'asset.company.logo' => SystemAsset::CATEGORY_LOGO,
            'asset.brand.logo'   => SystemAsset::CATEGORY_LOGO,
            'asset.letterhead'   => SystemAsset::CATEGORY_LETTERHEAD,
            'asset.signature'    => SystemAsset::CATEGORY_SIGNATURE,
            'asset.stamp'        => SystemAsset::CATEGORY_STAMP,
            'asset.background'   => SystemAsset::CATEGORY_BACKGROUND,
        ];

        foreach ($assetCategories as $tokenKey => $category) {
            $resolvedAsset = null;

            // 1. Cek explicit override
            if (isset($assetOverrides[$tokenKey]) && is_numeric($assetOverrides[$tokenKey])) {
                $resolvedAsset = SystemAsset::active()->find($assetOverrides[$tokenKey]);
            }

            // 2. Jika tidak ada override, resolve via SystemAssetService (Brand -> Institution -> Global Default -> Global)
            if (!$resolvedAsset) {
                $brandId = $brandModel?->id;
                $instId = $instModel?->id;
                $resolvedAsset = $this->assetService->resolveAsset(
                    category: $category,
                    assetCode: $tokenKey,
                    brandId: $tokenKey === 'asset.brand.logo' ? $brandId : null,
                    institutionId: $tokenKey === 'asset.institution.logo' ? $instId : null
                );
            }

            if ($resolvedAsset) {
                $assetReferences[$tokenKey] = [
                    'asset_id'   => $resolvedAsset->id,
                    'name'       => $resolvedAsset->name,
                    'version'    => $resolvedAsset->version,
                    'checksum'   => $resolvedAsset->checksum_sha256,
                    'category'   => $resolvedAsset->category,
                ];

                $dataUri = $this->assetService->getAssetAsBase64DataUri($resolvedAsset);
                $resolvedImages[$tokenKey] = $dataUri;
                $variables[$tokenKey] = $dataUri;
            } else {
                // Fallback default image placeholder
                $fallback = match ($category) {
                    SystemAsset::CATEGORY_LOGO       => 'images/logos/logo_seveninc.png',
                    SystemAsset::CATEGORY_SIGNATURE  => 'images/signature/ttd_arisetiahusbana.png',
                    SystemAsset::CATEGORY_STAMP      => 'images/signature/ttd_arisetiahusbana.png',
                    default                          => null,
                };

                $dataUri = $fallback ? $this->assetService->getAssetAsBase64DataUri(null, $fallback) : null;
                $resolvedImages[$tokenKey] = $dataUri;
                $variables[$tokenKey] = $dataUri;
            }
        }

        return [
            'variables'        => $variables,
            'asset_references' => $assetReferences,
            'resolved_images'  => $resolvedImages,
        ];
    }

    /**
     * Gantikan token placeholder di dalam konten teks atau HTML dengan nilai yang telah diselesaikan.
     */
    public function renderContent(string $templateContent, array $resolvedContext): string
    {
        $variables = $resolvedContext['variables'] ?? [];
        $rendered = $templateContent;

        // Ambil dictionary aliases dari registry
        $definitions = VariableRegistry::getDefinitions();

        foreach ($definitions as $mainKey => $def) {
            $val = $variables[$mainKey] ?? ($def['sample'] ?? '');

            // Ganti format {{mainKey}} dan {{ mainKey }}
            $rendered = str_replace(
                ["{{" . $mainKey . "}}", "{{ " . $mainKey . " }}"],
                (string) $val,
                $rendered
            );

            // Ganti seluruh aliases (seperti [institution_name], {{institution_name}}, dll.)
            foreach ($def['aliases'] as $alias) {
                $rendered = str_replace(
                    [
                        "[" . $alias . "]",
                        "{{" . $alias . "}}",
                        "{{ " . $alias . " }}",
                    ],
                    (string) $val,
                    $rendered
                );
            }
        }

        return $rendered;
    }
}
