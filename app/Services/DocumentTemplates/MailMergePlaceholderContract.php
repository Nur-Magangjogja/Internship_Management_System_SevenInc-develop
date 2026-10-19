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

        // Integrasi adaptif dengan VariableRegistry Antigravity 1 jika terdaftar
        if (class_exists(\App\Services\MailMerge\VariableRegistry::class)) {
            $defs = \App\Services\MailMerge\VariableRegistry::getDefinitions();
            foreach ($defs as $mainKey => $def) {
                if (isset($flatValues[$mainKey])) {
                    foreach ($def['aliases'] ?? [] as $alias) {
                        if (!isset($flatValues[$alias])) {
                            $flatValues[$alias] = $flatValues[$mainKey];
                        }
                    }
                }
            }
        }

        // Nilai aset gambar standar
        $compLogo   = $flatValues['asset.company.logo'] ?? '/images/mou/seven-inc-logo.png';
        $instLogo   = $flatValues['asset.institution.logo'] ?? '/images/mou/institution-logo-placeholder.png';
        $sigImage   = $flatValues['asset.signature'] ?? '/images/mou/signature-sample.png';
        $stampImage = $flatValues['asset.stamp'] ?? '/images/mou/stamp-sample.png';

        $resolved = $content;

        // 1. Substitusi atribut HTML seperti src="{{asset.company.logo}}" (hanya ganti URL, bukan tag <img> ganda)
        $resolved = preg_replace_callback('/(src=["\'])\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}(["\'])/i', function ($m) use ($flatValues) {
            $key = $m[2];
            $val = $flatValues[$key] ?? '';
            return $m[1] . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . $m[3];
        }, $resolved);

        // 2. Substitusi tag aset yang berdiri sendiri di body teks menjadi tag <img>
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
