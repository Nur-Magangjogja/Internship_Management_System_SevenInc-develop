<?php

namespace App\Contracts;

interface CloudFolderQueryInterface
{
    /**
     * Dapatkan daftar berkas anak dalam folder tertentu (dengan pagination token).
     */
    public function listChildren(string $folderId, ?string $pageToken = null, int $pageSize = 50): array;

    /**
     * Cari berkas dalam scope root folder kanonikal.
     */
    public function searchWithinCanonicalRoot(string $canonicalRootId, string $query, ?string $pageToken = null, int $pageSize = 50): array;
}
