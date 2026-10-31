<?php

namespace App\Services;

use InvalidArgumentException;

class BrandDomainInputService
{
    /** Normalize a hostname or an origin-only HTTP(S) URL into a DNS hostname. */
    public function normalize(?string $input): ?string
    {
        $value = trim((string) $input);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            $parts = parse_url($value);
            if (!is_array($parts)
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || empty($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['port'])
                || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
                || isset($parts['query'])
                || isset($parts['fragment'])) {
                throw new InvalidArgumentException('Masukkan nama domain saja, tanpa path, port, query, atau kredensial URL.');
            }
            $value = (string) $parts['host'];
        } elseif (preg_match('/[\/:?#@\\\\]/', $value)) {
            throw new InvalidArgumentException('Masukkan nama domain saja, tanpa protokol, path, port, atau karakter URL lain.');
        }

        $host = strtolower(rtrim(trim($value), '.'));
        if (filter_var($host, FILTER_VALIDATE_IP)
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
            throw new InvalidArgumentException('Format domain tidak valid. Gunakan hostname publik yang valid, misalnya daftar.brandbaru.id.');
        }

        return $host;
    }

    public function registrationHostBelongsToPrimaryDomain(?string $registrationHost, ?string $primaryDomain): bool
    {
        if ($registrationHost === null && $primaryDomain === null) {
            return true;
        }
        if ($registrationHost === null || $primaryDomain === null) {
            return false;
        }

        return $registrationHost === $primaryDomain
            || str_ends_with($registrationHost, '.' . $primaryDomain);
    }
}
