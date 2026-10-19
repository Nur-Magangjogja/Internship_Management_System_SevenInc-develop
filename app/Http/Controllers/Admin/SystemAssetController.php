<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Institution;
use App\Models\SystemAsset;
use App\Services\MailMerge\VariableRegistry;
use App\Services\SystemAssetService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SystemAssetController extends Controller
{
    public function __construct(
        protected SystemAssetService $assetService
    ) {}

    /**
     * Tampilkan daftar pustaka aset dan dokumen sistem.
     */
    public function index(Request $request)
    {
        $query = SystemAsset::with(['brand', 'institution', 'createdByUser'])
            ->latest('id');

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('scope')) {
            $query->where('scope', $request->query('scope'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->query('brand_id'));
        }

        if ($request->filled('search')) {
            $s = trim($request->query('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'LIKE', "%{$s}%")
                  ->orWhere('asset_code', 'LIKE', "%{$s}%");
            });
        }

        $assets = $query->paginate(15)->withQueryString();

        $brands = Brand::orderBy('name')->get();
        $institutions = Institution::orderBy('name')->get();
        $groupedVariables = VariableRegistry::getGroupedForEditor();

        return view('admin.system-assets.index', [
            'assets'           => $assets,
            'brands'           => $brands,
            'institutions'     => $institutions,
            'groupedVariables' => $groupedVariables,
            'categories'       => SystemAsset::VALID_CATEGORIES,
            'scopes'           => SystemAsset::VALID_SCOPES,
        ]);
    }

    /**
     * Unggah aset sistem baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'asset_code'     => 'nullable|string|max:100',
            'category'       => 'required|string|in:' . implode(',', SystemAsset::VALID_CATEGORIES),
            'scope'          => 'required|string|in:' . implode(',', SystemAsset::VALID_SCOPES),
            'brand_id'       => 'nullable|required_if:scope,brand|exists:brands,id',
            'institution_id' => 'nullable|required_if:scope,institution|exists:institutions,id',
            'file'           => 'required|file|mimes:png,jpg,jpeg,svg,pdf,webp|max:10240', // Maks 10MB
            'is_default'     => 'nullable|boolean',
        ]);

        $validated['is_default'] = $request->has('is_default');

        $this->assetService->storeAsset($request->file('file'), $validated, Auth::user());

        return redirect()->route('admin.system-assets.index')
            ->with('success', "Aset '{$validated['name']}' berhasil ditambahkan ke Perpustakaan Aset.");
    }

    /**
     * Ganti versi aset dengan berkas baru tanpa menghapus berkas fisik versi lama.
     */
    public function replaceVersion(Request $request, SystemAsset $systemAsset)
    {
        $request->validate([
            'file' => 'required|file|mimes:png,jpg,jpeg,svg,pdf,webp|max:10240',
        ]);

        $this->assetService->replaceVersion($systemAsset, $request->file('file'), Auth::user());

        return redirect()->route('admin.system-assets.index')
            ->with('success', "Aset '{$systemAsset->name}' berhasil diperbarui ke versi {$systemAsset->fresh()->version}.");
    }

    /**
     * Toggle status aktif aset.
     */
    public function toggleActive(SystemAsset $systemAsset)
    {
        $systemAsset->update(['is_active' => !$systemAsset->is_active]);

        $statusLabel = $systemAsset->is_active ? 'diaktifkan' : 'dinonaktifkan';
        return redirect()->route('admin.system-assets.index')
            ->with('success', "Aset '{$systemAsset->name}' telah {$statusLabel}.");
    }

    /**
     * Jadikan aset sebagai default pada kategori dan scope-nya.
     */
    public function setDefault(SystemAsset $systemAsset)
    {
        SystemAsset::where('category', $systemAsset->category)
            ->where('scope', $systemAsset->scope)
            ->when($systemAsset->brand_id, fn ($q) => $q->where('brand_id', $systemAsset->brand_id))
            ->when($systemAsset->institution_id, fn ($q) => $q->where('institution_id', $systemAsset->institution_id))
            ->update(['is_default' => false]);

        $systemAsset->update(['is_default' => true]);

        return redirect()->route('admin.system-assets.index')
            ->with('success', "Aset '{$systemAsset->name}' ditetapkan sebagai default.");
    }

    /**
     * Stream preview berkas aset secara aman dengan proteksi otentikasi.
     */
    public function preview(SystemAsset $systemAsset)
    {
        try {
            $binary = $this->assetService->getAssetFileBinary($systemAsset);

            return response($binary['content'], 200, [
                'Content-Type'        => $binary['mime_type'],
                'Content-Disposition' => 'inline; filename="' . $binary['filename'] . '"',
                'Cache-Control'       => 'private, max-age=3600',
            ]);
        } catch (Exception $e) {
            abort(404, 'Berkas fisik aset tidak ditemukan di server.');
        }
    }

    /**
     * Sinkronisasikan aset ke Google Drive secara manual.
     */
    public function syncDrive(SystemAsset $systemAsset)
    {
        try {
            $res = $this->assetService->syncAssetToDrive($systemAsset, Auth::user());
            return redirect()->route('admin.system-assets.index')
                ->with('success', "Aset '{$systemAsset->name}' berhasil disinkronkan ke Google Drive.");
        } catch (Exception $e) {
            return redirect()->route('admin.system-assets.index')
                ->with('error', "Gagal sinkronisasi Google Drive: " . $e->getMessage());
        }
    }

    /**
     * Arsipkan / hapus lunak aset.
     */
    public function destroy(SystemAsset $systemAsset)
    {
        $systemAsset->update(['archived_at' => now(), 'is_active' => false]);
        $systemAsset->delete();

        return redirect()->route('admin.system-assets.index')
            ->with('success', "Aset '{$systemAsset->name}' telah diarsipkan.");
    }
}
