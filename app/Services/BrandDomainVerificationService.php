<?php

namespace App\Services;

class BrandDomainVerificationService
{
    public function txtRecordName(string $primaryDomain): string
    {
        return '_seveninc-verification.' . strtolower(rtrim(trim($primaryDomain), '.'));
    }

    /** Verify ownership using an exact DNS TXT token. */
    public function verify(string $primaryDomain, string $token): bool
    {
        if (!function_exists('dns_get_record') || !defined('DNS_TXT')) {
            return false;
        }

        $recordName = $this->txtRecordName($primaryDomain);
        $records = @dns_get_record($recordName, DNS_TXT);
        if (!is_array($records)) {
            return false;
        }

        $expected = 'seveninc-domain-verification=' . trim($token);
        foreach ($records as $record) {
            $value = trim((string) ($record['txt'] ?? ''));
            if ($value === $expected) {
                return true;
            }

            // Some DNS providers split TXT strings into entries.
            $entries = $record['entries'] ?? [];
            if (is_array($entries) && trim(implode('', $entries)) === $expected) {
                return true;
            }
        }

        return false;
    }
}
