<?php

namespace App\Services\CloudStorage;

interface CloudStorageInterface
{
    /**
     * Cek apakah driver telah dikonfigurasi dan siap digunakan.
     */
    public function isConfigured(): bool;

    /**
     * Unggah berkas dari path lokal ke target folder cloud storage.
     *
     * @param string $localFullPath Path fisik berkas lokal
     * @param string $remoteFolderPath Struktur folder cloud tujuan (misal: "Institusi/smk-negeri-1/MOU" atau "Arsip/2026")
     * @param string|null $customFileName Nama berkas di remote storage (opsional)
     * @return array [ 'file_id' => string, 'web_view_link' => string, 'file_name' => string, 'driver' => string ]
     */
    public function uploadFile(string $localFullPath, string $remoteFolderPath, ?string $customFileName = null): array;

    /**
     * Hapus berkas dari cloud storage berdasarkan remote file ID.
     */
    public function deleteFile(string $remoteFileId): bool;

    /**
     * Nama identitas driver penyimpanan.
     */
    public function getDriverName(): string;
}
