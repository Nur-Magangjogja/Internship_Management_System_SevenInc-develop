<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HistoricalImportBatch;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Services\HistoricalImport\HistoricalImportPipelineService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LegacyImportController extends Controller
{
    public function __construct(
        protected HistoricalImportPipelineService $pipelineService
    ) {}

    /**
     * Tampilkan halaman awal wizard impor data historis.
     */
    public function index()
    {
        $recentBatches = HistoricalImportBatch::latest('id')->take(10)->get();
        $registrationConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();

        return view('admin.legacy-import.index', compact('recentBatches', 'registrationConnection'));
    }

    /**
     * Unggah berkas dan analisis struktur header dan baris sampel.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'import_file' => 'required|file|max:15360|mimes:xlsx,csv,txt', // maks 15MB
            'sheet_index' => 'nullable|integer|min:0',
        ]);

        $file = $request->file('import_file');
        $ext = strtolower($file->getClientOriginalExtension());
        $sourceType = ($ext === 'xlsx') ? HistoricalImportBatch::SOURCE_XLSX : HistoricalImportBatch::SOURCE_CSV;
        $sheetIndex = (int) $request->input('sheet_index', 0);

        try {
            $analysis = $this->pipelineService->analyzeSourceFile($sourceType, $file->getRealPath(), $sheetIndex);

            // Simpan berkas sementara untuk tahap staging
            $tempFileName = 'import_' . uniqid() . '.' . $ext;
            $tempPath = $file->storeAs('temp-imports', $tempFileName, 'local');

            return response()->json([
                'success'         => true,
                'source_type'     => $sourceType,
                'temp_path'       => $tempPath,
                'source_filename' => $file->getClientOriginalName(),
                'headers'         => $analysis['headers'],
                'sample_rows'     => $analysis['sample_rows'],
                'total_rows'      => $analysis['total_rows'],
                'sheets'          => $analysis['sheets'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membaca berkas: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Periksa dan analisis sheet Google Spreadsheet lama secara read-only.
     */
    public function previewGoogleSheet(Request $request)
    {
        $request->validate([
            'spreadsheet_input' => 'required|string',
            'tab_name'          => 'nullable|string',
        ]);

        $rawInput = trim($request->input('spreadsheet_input'));
        $tabName = $request->input('tab_name') ? trim($request->input('tab_name')) : null;

        // Ekstrak ID jika admin memasukkan tautan Google Sheets lengkap
        $spreadsheetId = $rawInput;
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $rawInput, $matches)) {
            $spreadsheetId = $matches[1];
        }

        try {
            $analysis = $this->pipelineService->analyzeGoogleSheet($spreadsheetId, $tabName);

            return response()->json(array_merge([
                'success'        => true,
                'source_type'    => HistoricalImportBatch::SOURCE_GOOGLE_SHEETS,
                'spreadsheet_id' => $spreadsheetId,
            ], $analysis));
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membaca Google Sheets: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Simpan pemetaan kolom dan muat data ke tabel staging.
     */
    public function stage(Request $request)
    {
        $request->validate([
            'source_type'           => 'required|in:xlsx,csv,google_sheets_readonly',
            'column_mapping_config' => 'required|array',
            'default_status'        => 'nullable|string|in:waiting,accepted,active,completed,exited,rejected',
            'start_date_filter'     => 'nullable|date',
            'end_date_filter'       => 'nullable|date',
            // Validasi bersyarat untuk berkas fisik
            'temp_path'             => 'required_if:source_type,xlsx,csv|nullable|string',
            'source_filename'       => 'required_if:source_type,xlsx,csv|nullable|string',
            'sheet_index'           => 'nullable|integer',
            // Validasi bersyarat untuk Google Sheets
            'spreadsheet_id'        => 'required_if:source_type,google_sheets_readonly|nullable|string',
            'tab_name'              => 'required_if:source_type,google_sheets_readonly|nullable|string',
        ]);

        $sourceType = $request->input('source_type');
        $defaultStatus = $request->input('default_status', InternshipRegistration::STATUS_WAITING);
        $startDateFilter = $request->input('start_date_filter');
        $endDateFilter = $request->input('end_date_filter');

        try {
            if ($sourceType === HistoricalImportBatch::SOURCE_GOOGLE_SHEETS) {
                $batch = $this->pipelineService->createStagingBatchFromGoogleSheets(
                    spreadsheetId: $request->input('spreadsheet_id'),
                    tabName: $request->input('tab_name'),
                    columnMappingConfig: $request->input('column_mapping_config'),
                    defaultStatus: $defaultStatus,
                    startDateFilter: $startDateFilter,
                    endDateFilter: $endDateFilter,
                    userId: Auth::id()
                );
            } else {
                $fullPath = storage_path('app/' . $request->input('temp_path'));
                if (!file_exists($fullPath)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Berkas sementara impor telah kedaluwarsa. Silakan unggah ulang.',
                    ], 404);
                }

                $batch = $this->pipelineService->createStagingBatch(
                    sourceType: $sourceType,
                    filePath: $fullPath,
                    sourceFilename: $request->input('source_filename'),
                    columnMappingConfig: $request->input('column_mapping_config'),
                    defaultStatus: $defaultStatus,
                    sheetIndex: (int) $request->input('sheet_index', 0),
                    userId: Auth::id(),
                    startDateFilter: $startDateFilter,
                    endDateFilter: $endDateFilter
                );

                // Hapus berkas upload sementara setelah sukses dimuat ke staging
                @unlink($fullPath);
            }

            return response()->json([
                'success'  => true,
                'batch_id' => $batch->id,
                'redirect' => route('admin.legacy-import.batch', $batch->id),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses staging: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tampilkan detail batch staging untuk verifikasi sebelum commit.
     */
    public function showBatch(HistoricalImportBatch $batch)
    {
        $rows = $batch->rows()->paginate(25);
        $stats = [
            'total'     => $batch->rows()->count(),
            'valid'     => $batch->rows()->where('validation_status', 'valid')->count(),
            'warning'   => $batch->rows()->where('validation_status', 'warning')->count(),
            'duplicate' => $batch->rows()->where('is_duplicate_event', true)->count(),
            'error'     => $batch->rows()->where('validation_status', 'error')->count(),
        ];

        return view('admin.legacy-import.show-batch', compact('batch', 'rows', 'stats'));
    }

    /**
     * Commit dan simpan data staging ke dalam database operasional.
     */
    public function commit(Request $request)
    {
        $request->validate([
            'batch_id' => 'required|exists:historical_import_batches,id',
        ]);

        $batch = HistoricalImportBatch::findOrFail($request->input('batch_id'));

        try {
            $result = $this->pipelineService->commitStagingBatch($batch);

            return redirect()
                ->route('admin.legacy-import.batch', $batch->id)
                ->with('success', "Batch impor berhasil dikomit: {$result['imported_count']} baris disimpan, {$result['skipped_count']} dilewati (duplikat), {$result['failed_count']} gagal.");
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal melakukan komit batch impor: ' . $e->getMessage());
        }
    }

    /**
     * Batalkan batch staging.
     */
    public function cancel(Request $request)
    {
        $request->validate([
            'batch_id' => 'required|exists:historical_import_batches,id',
        ]);

        $batch = HistoricalImportBatch::findOrFail($request->input('batch_id'));
        if ($batch->status === HistoricalImportBatch::STATUS_COMMITTED) {
            return redirect()->back()->with('error', 'Batch yang sudah dikomit tidak dapat dibatalkan.');
        }

        $batch->delete();

        return redirect()
            ->route('admin.legacy-import.index')
            ->with('success', 'Sesi staging impor berhasil dibatalkan.');
    }

    /**
     * Unduh template standar contoh untuk impor historis.
     */
    public function downloadTemplate()
    {
        $headers = [
            'Timestamp', 'Nama Lengkap', 'NIM/NIS', 'Program Studi', 'Email',
            'Jenis Kelamin', 'No. HP Aktif', 'Asal Sekolah/Kampus', 'Alasan Magang',
            'Jenis Magang', 'Status Saat Ini', 'Kesiapan WFO', 'Kontak Pembimbing',
            'Kesiapan Shifting', 'Punya Laptop', 'Alat yang Dimiliki', 'Rencana Mulai',
            'Durasi Magang', 'Sumber Info', 'Instagram', 'Info Kost', 'Bidang Minat',
            'Status Magang Terverifikasi'
        ];

        $callback = function () use ($headers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fputcsv($handle, [
                date('Y-m-d H:i:s'), 'Contoh Nama', '12345678', 'Teknik Informatika',
                'contoh@email.com', 'Laki-laki', '081234567890', 'Universitas Contoh',
                'Ingin mencari pengalaman', 'Magang Mandiri', 'Mahasiswa', 'Siap',
                'Dosen Pembimbing - 081234567891', 'Sanggup', 'YA', 'Laptop Asus',
                '2026-02-01', '3 Bulan', 'Instagram', '@contoh', 'Tidak', 'Web Development',
                'active'
            ]);
            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="template_impor_historis_sertif.csv"',
        ]);
    }
}
