<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArchiveExport;
use App\Services\ArchiveExportService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ArchiveExportController extends Controller
{
    protected ArchiveExportService $archiveExportService;

    public function __construct(ArchiveExportService $archiveExportService)
    {
        $this->archiveExportService = $archiveExportService;
    }

    /**
     * Tampilkan riwayat arsip historis (read-only) atau informasikan status pensiun.
     */
    public function index(Request $request)
    {
        abort(410, 'Fitur ekspor arsip tahunan telah dipensiunkan. Integrasi data kini menggunakan Google Spreadsheet mirroring.');
    }

    /**
     * Endpoint API kalkulasi preview data (dipensiunkan).
     */
    public function preview(Request $request)
    {
        abort(410, 'Fitur preview ekspor arsip tahunan telah dipensiunkan.');
    }

    /**
     * Generate paket arsip ZIP (dipensiunkan - mutasi ditolak).
     */
    public function generate(Request $request)
    {
        abort(410, 'Fitur pembuatan paket arsip tahunan telah dipensiunkan. Seluruh data aktif tersinkronisasi via Google Spreadsheet integration.');
    }

    /**
     * Unduh berkas ZIP arsip historis yang tersimpan di storage lokal.
     * Preserved strictly for historical compliance/audit.
     */
    public function download(ArchiveExport $archiveExport)
    {
        if (!Storage::disk('local')->exists($archiveExport->file_path)) {
            return redirect()
                ->back()
                ->with('error', 'Berkas fisik arsip tidak ditemukan di server.');
        }

        \App\Services\ActivityLogger::log(
            action: 'archive_downloaded',
            description: "Admin mengunduh paket arsip tahun {$archiveExport->archive_year} ({$archiveExport->file_name})",
            subject: $archiveExport,
            actor: Auth::user(),
            metadata: [
                'archive_year' => $archiveExport->archive_year,
                'file_name'    => $archiveExport->file_name,
                'public_uuid'  => $archiveExport->public_uuid,
            ]
        );

        return Storage::disk('local')->download(
            $archiveExport->file_path,
            $archiveExport->file_name,
            [
                'Content-Type' => 'application/zip',
            ]
        );
    }

    /**
     * Hapus berkas arsip (dipensiunkan - penghapusan arsip historis dilarang).
     */
    public function destroy(ArchiveExport $archiveExport)
    {
        abort(410, 'Penghapusan arsip historis tidak diizinkan; data dipertahankan untuk audit.');
    }

    /**
     * Sinkronisasikan paket arsip ke Cloud Storage (dipensiunkan).
     */
    public function syncDrive(ArchiveExport $archiveExport, \App\Services\CloudStorage\CloudSyncManager $syncManager)
    {
        abort(410, 'Fitur sinkronisasi arsip ke Cloud Storage telah dipensiunkan; sinkronisasi outbound menggunakan Google Spreadsheet integration.');
    }
}

