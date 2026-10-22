<?php

namespace App\Services\DocumentTemplates;

use App\Models\DocumentTemplate;
use App\Services\MailMerge\VariableRegistry;

class MailMergePlaceholderContract
{
    /**
     * Dapatkan daftar seluruh placeholder dan deskripsinya dari VariableRegistry universal.
     */
    public static function getAvailablePlaceholders(?string $documentType = null): array
    {
        $tokens = $documentType
            ? VariableRegistry::getTokensForDocumentType($documentType)
            : VariableRegistry::getDefinitions();

        $result = [];
        foreach ($tokens as $key => $def) {
            $result['{{' . $key . '}}'] = [
                'label'       => $def['label'],
                'group'       => $def['category'],
                'example'     => $def['sample'] ?? '',
                'description' => "Sumber: {$def['source']}",
                'canonical'   => $key,
                'data_type'   => $def['data_type'] ?? 'string',
                'required'    => ($def['required_policy'] ?? '') === VariableRegistry::POLICY_REQUIRED,
            ];
        }

        return $result;
    }

    /**
     * Ambil data mock contoh untuk Live Preview terisolasi.
     */
    public static function getMockData(?string $documentType = null): array
    {
        $tokens = $documentType
            ? VariableRegistry::getTokensForDocumentType($documentType)
            : VariableRegistry::getDefinitions();

        $mock = [];
        foreach ($tokens as $key => $def) {
            if (isset($def['sample']) && $def['sample'] !== null) {
                $mock[$key] = $def['sample'];
            }
        }

        return $mock;
    }

    /**
     * Substitusi variabel Mail Merge dalam string teks / HTML secara aman.
     * Mendukung pemetaan seluruh alias kanonis tanpa kebocoran data mock pada penerbitan resmi.
     */
    public static function resolve(string $content, array $data = [], bool $isLiveIssuance = false): string
    {
        // Hanya isi mock jika BUKAN penerbitan resmi dan data eksplisit kosong
        $dataset = (!empty($data) || $isLiveIssuance) ? $data : static::getMockData();
        $flatValues = static::flattenDataset($dataset);

        // Integrasi adaptif dengan VariableRegistry universal
        $aliasesMap = VariableRegistry::getAliasesMap();
        foreach ($flatValues as $k => $v) {
            $lower = strtolower($k);
            $canonical = $aliasesMap[$lower] ?? $k;
            if (!isset($flatValues[$canonical])) {
                $flatValues[$canonical] = $v;
            }
        }

        $defs = VariableRegistry::getDefinitions();
        foreach ($defs as $mainKey => $def) {
            if (isset($flatValues[$mainKey])) {
                foreach ($def['aliases'] ?? [] as $alias) {
                    if (!isset($flatValues[$alias])) {
                        $flatValues[$alias] = $flatValues[$mainKey];
                    }
                }
            } else {
                foreach ($def['aliases'] ?? [] as $alias) {
                    if (isset($flatValues[$alias])) {
                        $flatValues[$mainKey] = $flatValues[$alias];
                        break;
                    }
                }
            }
        }

        // Compatibility fallback: if company_mou_number is not set from mou.nomor_perusahaan, fall back to dokumen.nomor
        if (!isset($flatValues['company_mou_number']) && isset($flatValues['dokumen.nomor'])) {
            $flatValues['company_mou_number'] = $flatValues['dokumen.nomor'];
        }

        // Normalisasi path aset lokal agar tidak ada prefix /storage/images/
        foreach ($flatValues as $k => $v) {
            if (is_string($v) && str_starts_with($v, '/storage/images/')) {
                $flatValues[$k] = substr($v, strlen('/storage'));
            }
        }

        // Resolusi aset gambar
        $compLogo   = $flatValues['asset.brand.logo'] ?? ($flatValues['asset.company.logo'] ?? null);
        $instLogo   = $flatValues['asset.institusi.logo'] ?? ($flatValues['asset.institution.logo'] ?? null);
        $sigImage   = $flatValues['asset.brand.tanda_tangan'] ?? ($flatValues['asset.signature'] ?? null);
        $stampImage = $flatValues['asset.brand.stempel'] ?? ($flatValues['asset.stamp'] ?? null);

        if (is_string($compLogo) && str_starts_with($compLogo, '/storage/images/')) {
            $compLogo = substr($compLogo, strlen('/storage'));
        }
        if (is_string($instLogo) && str_starts_with($instLogo, '/storage/images/')) {
            $instLogo = substr($instLogo, strlen('/storage'));
        }
        if (is_string($sigImage) && str_starts_with($sigImage, '/storage/images/')) {
            $sigImage = substr($sigImage, strlen('/storage'));
        }
        if (is_string($stampImage) && str_starts_with($stampImage, '/storage/images/')) {
            $stampImage = substr($stampImage, strlen('/storage'));
        }

        // Fallback hanya untuk preview mock (INV-05, INV-06, INV-14)
        if (!$isLiveIssuance) {
            $compLogo   = $compLogo ?: '/images/mou/seven-inc-logo.png';
            $instLogo   = $instLogo ?: '/images/mou/institution-logo-placeholder.png';
            $sigImage   = $sigImage ?: '/images/mou/signature-sample.png';
            $stampImage = $stampImage ?: '/images/mou/stamp-sample.png';
        }

        $resolved = $content;

        // 1. Substitusi atribut HTML seperti src="{{asset.company.logo}}" (hanya ganti URL)
        $resolved = preg_replace_callback('/(src=["\'])\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}(["\'])/i', function ($m) use ($flatValues) {
            $key = $m[2];
            $val = $flatValues[$key] ?? '';
            return $m[1] . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . $m[3];
        }, $resolved);

        // 2. Substitusi tag aset yang berdiri sendiri di body teks menjadi tag <img>
        $resolved = preg_replace_callback('/\{\{\s*(asset\.(?:brand|company)\.logo|company_logo|brand_logo)\s*\}\}|\[\s*(asset\.(?:brand|company)\.logo|company_logo|brand_logo)\s*\]/i', function () use ($compLogo) {
            if (!$compLogo) {
                return '';
            }
            return '<img src="' . htmlspecialchars($compLogo, ENT_QUOTES, 'UTF-8') . '" alt="Logo Perusahaan" style="max-height: 90px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        $resolved = preg_replace_callback('/\{\{\s*(asset\.(?:institusi|institution)\.logo|institution_logo|logo_institusi)\s*\}\}|\[\s*(asset\.(?:institusi|institution)\.logo|institution_logo|logo_institusi)\s*\]/i', function () use ($instLogo) {
            if (!$instLogo) {
                return '';
            }
            return '<img src="' . htmlspecialchars($instLogo, ENT_QUOTES, 'UTF-8') . '" alt="Logo Institusi" style="max-height: 90px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        $resolved = preg_replace_callback('/\{\{\s*(asset\.(?:brand\.)?tanda_tangan|asset\.signature|signature|tanda_tangan)\s*\}\}|\[\s*(asset\.(?:brand\.)?tanda_tangan|asset\.signature|signature|tanda_tangan)\s*\]/i', function () use ($sigImage) {
            if (!$sigImage) {
                return '';
            }
            return '<img src="' . htmlspecialchars($sigImage, ENT_QUOTES, 'UTF-8') . '" alt="Tanda Tangan" style="height: 65px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        $resolved = preg_replace_callback('/\{\{\s*(asset\.(?:brand\.)?stempel|asset\.stamp|stamp|stempel)\s*\}\}|\[\s*(asset\.(?:brand\.)?stempel|asset\.stamp|stamp|stempel)\s*\]/i', function () use ($stampImage) {
            if (!$stampImage) {
                return '';
            }
            return '<img src="' . htmlspecialchars($stampImage, ENT_QUOTES, 'UTF-8') . '" alt="Stempel Resmi" style="height: 105px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        // 3. Substitusi seluruh variabel skalar (teks, tanggal, nomor)
        foreach ($flatValues as $key => $val) {
            $escapedKey = preg_quote($key, '/');
            $safeVal = htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
            $pattern = '/\{\{\s*' . $escapedKey . '\s*\}\}|\[\s*' . $escapedKey . '\s*\]/i';
            $resolved = preg_replace($pattern, $safeVal, $resolved);
        }

        return $resolved;
    }

    /**
     * Ratakan array multidimensi menjadi format dot notation (e.g. pemagang.nama).
     */
    public static function flattenDataset(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix === '' ? $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $result = array_merge($result, static::flattenDataset($value, $fullKey));
            } else {
                $result[$fullKey] = (string) $value;
            }
        }
        return $result;
    }
}
