<?php

namespace App\Services\AbsenIntegration\Exceptions;

use RuntimeException;

class UnresolvedMasterDataException extends RuntimeException
{
    public function __construct(
        public readonly string $entityType,
        public readonly int $sourceId,
        string $message = ''
    ) {
        parent::__construct(
            $message ?: "Master data mapping belum tersedia: IMS {$entityType} ID [{$sourceId}] belum dipetakan ke target Absen Djuragan."
        );
    }
}
