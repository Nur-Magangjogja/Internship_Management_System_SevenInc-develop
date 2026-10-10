<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LegacyImportService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LegacyImportController extends Controller
{
    protected LegacyImportService $legacyImportService;

    public function __construct(LegacyImportService $legacyImportService)
    {
        $this->legacyImportService = $legacyImportService;
    }

    /**
     * Tampilkan halaman awal unggah berkas data legacy (dipensiunkan).
     */
    public function index()
    {
        abort(410, 'Fitur impor data legacy telah dipensiunkan. Database SertIF merupakan satu-satunya sumber kebenaran (source of truth).');
    }

    /**
     * Parse dan pratinjau data CSV sebelum disimpan permanen (dipensiunkan - mutasi ditolak).
     */
    public function preview(Request $request)
    {
        abort(410, 'Fitur impor data legacy telah dipensiunkan. Pengunggahan data CSV tidak lagi diizinkan.');
    }

    /**
     * Commit dan simpan data staging ke dalam database (dipensiunkan - mutasi ditolak).
     */
    public function commit(Request $request)
    {
        abort(410, 'Fitur impor data legacy telah dipensiunkan. Penyimpanan data impor tidak lagi diizinkan.');
    }

    /**
     * Batalkan sesi data staging yang belum di-commit (dipensiunkan).
     */
    public function cancel()
    {
        abort(410, 'Fitur impor data legacy telah dipensiunkan.');
    }

    /**
     * Unduh berkas template contoh CSV standar untuk impor data legacy (dipensiunkan).
     */
    public function downloadTemplate()
    {
        abort(410, 'Fitur unduh template impor data legacy telah dipensiunkan.');
    }
}
