<?php

namespace App\Services\CloudStorage;

use App\Contracts\CloudFileReadInterface;
use App\Contracts\CloudFolderQueryInterface;

/**
 * Kontrak terpadu Cloud Storage Provider SERTIF.
 * Menggabungkan kapabilitas tulis (upload, delete), baca (metadata, download, stream),
 * pencarian folder, serta resolusi folder kanonikal.
 */
interface CloudStorageProviderInterface extends CloudStorageInterface, CloudFileReadInterface, CloudFolderQueryInterface
{
    /**
     * Dapatkan atau buat folder hierarkis pada penyimpanan cloud remote
     * dan kembalikan ID remote foldernya.
     *
     * @param string $folderPath Struktur path folder relatif (misal: "REGISTRASI/2026/REG-1/CV")
     * @return string ID folder remote (Drive Folder ID)
     */
    public function getOrCreateFolderPath(string $folderPath): ?string;

    /**
     * Set ID folder root kanonikal untuk membatasi ruang lingkup penyimpanan.
     *
     * @param string|null $folderId ID folder Google Drive kanonikal
     * @return self
     */
    public function setRootFolderId(?string $folderId): self;

    /**
     * Dapatkan ID folder root kanonikal saat ini.
     *
     * @return string|null
     */
    public function getRootFolderId(): ?string;
}
