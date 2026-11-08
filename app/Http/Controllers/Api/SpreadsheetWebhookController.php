<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpreadsheetConnection;
use App\Services\ActiveSheetManualRowReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SpreadsheetWebhookController extends Controller
{
    /**
     * Webhook live push untuk menerima baris spreadsheet baru yang belum memiliki SERTIF_SYNC_ID.
     */
    public function pushUnsynced(
        Request $request,
        ActiveSheetManualRowReconciliationService $reconcileService
    ): JsonResponse {
        // 1. Verifikasi Kredensial / Shared Secret
        $serverSecret = config('services.google_apps_script.shared_secret');
        if (!empty($serverSecret)) {
            $providedSecret = $request->header('X-Shared-Secret')
                ?: $request->header('X-API-KEY')
                ?: $request->input('shared_secret');

            $signature = $request->header('X-Signature');
            $timestamp = $request->header('X-Timestamp', $request->input('timestamp'));
            $nonce     = $request->header('X-Nonce', $request->input('nonce'));

            $isSecretValid = !empty($providedSecret) && hash_equals((string) $serverSecret, (string) $providedSecret);

            $isSignatureValid = false;
            if (!empty($signature) && !empty($timestamp) && !empty($nonce)) {
                $rawBody = $request->getContent();
                $expectedSig = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $rawBody, $serverSecret);
                $isSignatureValid = hash_equals($expectedSig, $signature);
            }

            if (!$isSecretValid && !$isSignatureValid) {
                Log::warning('Unauthorized spreadsheet live push attempt.', [
                    'ip' => $request->ip(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Autentikasi ditolak. Kunci shared secret atau signature tidak valid.',
                ], 401);
            }
        }

        // 2. Validasi Struktur Payload
        $validated = $request->validate([
            'spreadsheet_id' => ['required', 'string', 'max:150'],
            'tab_name'       => ['required', 'string', 'max:150'],
            'rows'           => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.row_index' => ['required', 'integer', 'min:2'],
            'rows.*.data'      => ['required', 'array'],
            'rows.*.status'    => ['nullable', 'string', 'in:waiting,pending,accepted,active,completed,rejected,exited'],
        ]);

        $spreadsheetId = trim($validated['spreadsheet_id']);
        $tabName = trim($validated['tab_name']);
        $rows = $validated['rows'];

        // 3. Temukan koneksi spreadsheet yang cocok
        $connection = SpreadsheetConnection::where('spreadsheet_id', $spreadsheetId)
            ->orWhere('active_spreadsheet_id', $spreadsheetId)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => "Koneksi spreadsheet dengan ID '{$spreadsheetId}' tidak ditemukan di sistem SertIF.",
            ], 404);
        }

        if (!$connection->is_enabled) {
            return response()->json([
                'success' => false,
                'message' => "Koneksi spreadsheet '{$connection->display_name}' sedang dinonaktifkan.",
            ], 403);
        }

        // 4. Proses rekonsiliasi dan pembuatan ID tiap baris
        $processed = [];
        $errors = [];

        foreach ($rows as $rowItem) {
            $rowIndex = (int) $rowItem['row_index'];
            $rowData  = (array) $rowItem['data'];
            $targetStatus = $rowItem['status'] ?? $connection->default_integrated_status ?? 'waiting';

            try {
                $res = $reconcileService->reconcileRowSyncId(
                    connection: $connection,
                    tabName: $tabName,
                    rowIndex: $rowIndex,
                    existingSyncId: $rowItem['sync_id'] ?? null,
                    registrationId: null,
                    expectedChecksum: null,
                    rowData: $rowData,
                    targetStatus: $targetStatus
                );

                if ($res['success'] ?? false) {
                    $processed[] = [
                        'row_index'       => $rowIndex,
                        'sync_id'         => $res['sync_id'],
                        'registration_id' => $res['registration_id'],
                    ];
                } else {
                    $errors[] = [
                        'row_index' => $rowIndex,
                        'message'   => $res['message'] ?? 'Gagal memproses baris.',
                    ];
                }
            } catch (\Throwable $e) {
                Log::error("Live push reconciliation error on row {$rowIndex}: " . $e->getMessage());
                $errors[] = [
                    'row_index' => $rowIndex,
                    'message'   => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success'   => true,
            'message'   => 'Live push berhasil diproses.',
            'processed' => $processed,
            'errors'    => $errors,
            'count'     => count($processed),
        ], 200);
    }
}
