<?php

namespace App\Services\DocumentTemplates;

use App\Models\DocumentTemplate;

class MailMergePlaceholderContract
{
    /**
     * Dapatkan daftar seluruh placeholder dan deskripsinya.
     */
    public static function getAvailablePlaceholders(): array
    {
        return DocumentTemplate::getDefaultPlaceholders();
    }

    /**
     * Ambil data mock contoh untuk Live Preview.
     */
    public static function getMockData(): array
    {
        return DocumentTemplate::getDefaultMockData();
    }

    /**
     * Substitusi variabel Mail Merge dalam string teks / HTML secara aman.
     */
    public static function resolve(string $content, array $data = []): string
    {
        $dataset = !empty($data) ? $data : static::getMockData();
        $flatValues = static::flattenDataset($dataset);

        // Replace image/asset tags first if asset URLs exist
        $compLogo = $flatValues['asset.company.logo'] ?? '/images/mou/seven-inc-logo.png';
        $instLogo = $flatValues['asset.institution.logo'] ?? '/images/mou/institution-logo-placeholder.png';
        $sigImage = $flatValues['asset.signature'] ?? '/images/mou/signature-sample.png';
        $stampImage = $flatValues['asset.stamp'] ?? '/images/mou/stamp-sample.png';
        $bgImage = $flatValues['asset.background'] ?? '/images/certificate/certificate-bg.png';

        $resolved = $content;

        // Replace asset placeholders with clean img tags when inside HTML content
        $resolved = preg_replace_callback('/\{\{\s*asset\.company\.logo\s*\}\}|\[asset\.company\.logo\]/i', function () use ($compLogo) {
            return '<img src="' . htmlspecialchars($compLogo, ENT_QUOTES, 'UTF-8') . '" alt="Logo Perusahaan" style="max-height: 90px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        $resolved = preg_replace_callback('/\{\{\s*asset\.institution\.logo\s*\}\}|\[asset\.institution\.logo\]/i', function () use ($instLogo) {
            return '<img src="' . htmlspecialchars($instLogo, ENT_QUOTES, 'UTF-8') . '" alt="Logo Institusi" style="max-height: 90px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        $resolved = preg_replace_callback('/\{\{\s*asset\.signature\s*\}\}|\[asset\.signature\]/i', function () use ($sigImage) {
            return '<img src="' . htmlspecialchars($sigImage, ENT_QUOTES, 'UTF-8') . '" alt="Tanda Tangan" style="height: 65px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        $resolved = preg_replace_callback('/\{\{\s*asset\.stamp\s*\}\}|\[asset\.stamp\]/i', function () use ($stampImage) {
            return '<img src="' . htmlspecialchars($stampImage, ENT_QUOTES, 'UTF-8') . '" alt="Stempel Resmi" style="height: 70px; width: auto; vertical-align: middle;" />';
        }, $resolved);

        // Now replace all other scalar variables
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
