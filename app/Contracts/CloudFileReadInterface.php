<?php

namespace App\Contracts;

interface CloudFileReadInterface
{
    /**
     * Dapatkan metadata berkas remote dari provider.
     */
    public function getFileMetadata(string $remoteFileId): array;

    /**
     * Unduh konten biner berkas remote dari provider.
     */
    public function downloadFileBytes(string $remoteFileId): string;

    /**
     * Buka stream berkas remote untuk streaming efisien.
     */
    public function openFileStream(string $remoteFileId): mixed;
}
