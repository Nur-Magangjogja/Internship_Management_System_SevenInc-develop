<?php

namespace App\Http\Controllers;

use App\Models\CloudFileRegistry;
use App\Services\UniversalFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UniversalFileController extends Controller
{
    public function __construct(
        protected UniversalFileService $fileService
    ) {}

    /**
     * Pratinjau berkas (inline display).
     */
    public function preview(Request $request, string $uuid)
    {
        $actor = Auth::user();
        $file = $this->fileService->get($uuid, $actor);
        $previewData = $this->fileService->preview($file, $actor);

        return response($previewData['bytes'], 200, [
            'Content-Type'        => $previewData['mime_type'],
            'Content-Disposition' => 'inline; filename="' . addslashes($previewData['filename']) . '"',
            'Content-Length'      => $previewData['size'],
            'Cache-Control'       => 'private, max-age=3600',
        ]);
    }

    /**
     * Unduh berkas (attachment download).
     */
    public function download(Request $request, string $uuid)
    {
        $actor = Auth::user();
        $file = $this->fileService->get($uuid, $actor);
        $bytes = $this->fileService->downloadBytes($file, $actor);

        return response($bytes, 200, [
            'Content-Type'        => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . addslashes($file->logical_filename) . '"',
            'Content-Length'      => strlen($bytes),
            'Cache-Control'       => 'private, no-transform',
        ]);
    }

    /**
     * Ambil metadata berkas registry (JSON).
     */
    public function metadata(Request $request, string $uuid)
    {
        $actor = Auth::user();
        $file = $this->fileService->get($uuid, $actor);

        return response()->json([
            'public_uuid'      => $file->public_uuid,
            'logical_filename' => $file->logical_filename,
            'category'         => $file->category,
            'owner_type'       => $file->owner_type,
            'byte_size'        => $file->byte_size,
            'mime_type'        => $file->mime_type,
            'checksum_sha256'  => $file->checksum_sha256,
            'sync_status'      => $file->sync_status,
            'verified_at'      => $file->verified_at?->toIso8601String(),
            'preview_url'      => route('cloud-files.preview', $file->public_uuid),
            'download_url'     => route('cloud-files.download', $file->public_uuid),
        ]);
    }
}
