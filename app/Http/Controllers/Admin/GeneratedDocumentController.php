<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GeneratedDocumentController extends Controller
{
    public function __construct(
        protected InternDocumentIssuanceService $issuanceService
    ) {}

    /**
     * Download file fisik PDF dokumen yang telah di-generate.
     */
    public function download(string $uuid): BinaryFileResponse
    {
        $user = auth()->user();
        if (!$user) {
            abort(401, 'Silakan login terlebih dahulu.');
        }

        $doc = GeneratedDocument::where('public_uuid', $uuid)->firstOrFail();
        $this->authorizeDocumentAccess($user, $doc);

        if (!Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'Berkas fisik dokumen tidak ditemukan di server penyimpanan.');
        }

        $fullPath = Storage::disk('local')->path($doc->file_path);

        return response()->download($fullPath, $doc->file_name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Tampilkan file fisik PDF dokumen di browser secara inline.
     */
    public function preview(string $uuid): BinaryFileResponse
    {
        $user = auth()->user();
        if (!$user) {
            abort(401, 'Silakan login terlebih dahulu.');
        }

        $doc = GeneratedDocument::where('public_uuid', $uuid)->firstOrFail();
        $this->authorizeDocumentAccess($user, $doc);

        if (!Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'Berkas fisik dokumen tidak ditemukan di server penyimpanan.');
        }

        $fullPath = Storage::disk('local')->path($doc->file_path);

        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $doc->file_name . '"',
        ]);
    }

    /**
     * Ulangi sinkronisasi cloud drive untuk dokumen tertentu secara idempotent.
     */
    public function retrySync(Request $request, string $uuid)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'super_admin', 'hr_staff'], true)) {
            abort(403, 'Hanya Admin atau HR Staff yang berwenang mengulangi sinkronisasi cloud.');
        }

        $doc = GeneratedDocument::where('public_uuid', $uuid)->firstOrFail();

        try {
            $result = $this->issuanceService->retryDocumentSync($doc, $user);

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'status'  => $doc->fresh()->status,
                    'message' => "Sinkronisasi dokumen {$doc->document_number} berhasil diulangi.",
                    'data'    => $result,
                ]);
            }

            return back()->with('success', "Sinkronisasi dokumen {$doc->document_number} berhasil diulangi.");
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'status'  => $doc->fresh()->status,
                    'message' => "Gagal mengulangi sinkronisasi: " . $e->getMessage(),
                ], 500);
            }

            return back()->with('error', "Gagal mengulangi sinkronisasi: " . $e->getMessage());
        }
    }

    /**
     * Kembalikan status sinkronisasi dokumen terkini via JSON.
     */
    public function status(string $uuid)
    {
        $user = auth()->user();
        if (!$user) {
            abort(401, 'Silakan login terlebih dahulu.');
        }

        $doc = GeneratedDocument::where('public_uuid', $uuid)->firstOrFail();
        $this->authorizeDocumentAccess($user, $doc);

        return response()->json([
            'uuid'                => $doc->public_uuid,
            'number'              => $doc->document_number,
            'type'                => $doc->document_type,
            'status'              => $doc->status,
            'drive_file_id'       => $doc->drive_file_id,
            'drive_folder_id'     => $doc->drive_folder_id,
            'drive_web_view_link' => $doc->drive_web_view_link,
            'retry_count'         => $doc->retry_count,
            'sync_error_message'  => $doc->sync_error_message,
            'status_history'      => $doc->status_history,
            'published_at'        => $doc->published_at?->toIso8601String(),
            'synced_at'           => $doc->synced_at?->toIso8601String(),
        ]);
    }

    /**
     * Validasi hak akses dokumen privat: staf berwenang melihat semua, pemagang hanya dokumen milik sendiri
     * yang statusnya memenuhi syarat penerbitan.
     */
    protected function authorizeDocumentAccess(\App\Models\User $user, GeneratedDocument $doc): void
    {
        if (method_exists($user, 'isBanned') && $user->isBanned()) {
            abort(403, 'Akun Anda dinonaktifkan.');
        }

        if (in_array($user->role, ['admin', 'superadmin', 'super_admin', 'hr_staff'], true)) {
            return;
        }

        $doc->loadMissing('internshipRegistration');
        $intern = $doc->internshipRegistration;
        $isOwner = $intern && (int) $intern->user_id === (int) $user->id;

        if (!$isOwner) {
            abort(403, 'Anda tidak berhak mengakses dokumen ini.');
        }

        // Pemagang hanya dapat mengakses dokumen yang status pemagangannya memenuhi syarat penerbitan
        if (!$this->issuanceService->canIssue($intern, $doc->document_type)) {
            abort(403, 'Status pemagang belum memenuhi syarat untuk mengakses dokumen ini.');
        }
    }
}
