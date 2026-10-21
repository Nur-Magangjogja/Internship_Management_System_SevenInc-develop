<?php

namespace App\Support\DatabaseSafety;

interface DatabaseBackupper
{
    /**
     * Produce a SQL backup of the given connection and verify it.
     *
     * Must return metadata ['path' => string, 'bytes' => int, 'sha256' => string, 'tables' => int]
     * or throw \App\Exceptions\DatabaseSafetyException when the backup cannot be produced or verified.
     */
    public function backupAndVerify(string $connectionName, string $databaseName): array;
}
