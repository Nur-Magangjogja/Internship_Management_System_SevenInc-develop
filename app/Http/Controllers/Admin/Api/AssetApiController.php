<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemAsset;
use App\Services\SystemAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetApiController extends Controller
{
    public function __construct(
        protected SystemAssetService $assetService
    ) {}

    /**
     * Cari dan dapatkan daftar aset sistem (digunakan oleh modal editor MoU & template dokumen).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 15), 50);
        $assets = $this->assetService->searchAssets($request->all(), $perPage);

        $data = collect($assets->items())->map(fn (SystemAsset $asset) => $this->transformAsset($asset));

        return response()->json([
            'ok'   => true,
            'data' => $data,
            'pagination' => [
                'total'        => $assets->total(),
                'per_page'     => $assets->perPage(),
                'current_page' => $assets->currentPage(),
                'last_page'    => $assets->lastPage(),
            ],
        ]);
    }

    /**
     * Dapatkan detail metadata aset berdasarkan ID atau UUID.
     */
    public function show(Request $request, string $idOrUuid): JsonResponse
    {
        $asset = is_numeric($idOrUuid)
            ? $this->assetService->getAssetById((int) $idOrUuid)
            : $this->assetService->getAssetByUuid($idOrUuid);

        if (!$asset) {
            return response()->json(['ok' => false, 'error' => 'Aset sistem tidak ditemukan.'], 404);
        }

        $transformed = $this->transformAsset($asset);

        if ($request->boolean('include_data_uri')) {
            $transformed['data_uri'] = $this->assetService->getAssetAsBase64DataUri($asset);
        }

        $transformed['usage_stats'] = $this->assetService->getUsageStats($asset);

        return response()->json([
            'ok'   => true,
            'data' => $transformed,
        ]);
    }

    /**
     * Selesaikan aset aktif terbaik untuk kebutuhan konteks dokumen secara langsung.
     */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category'       => 'required|string|in:' . implode(',', SystemAsset::VALID_CATEGORIES),
            'asset_code'     => 'nullable|string',
            'brand_id'       => 'nullable|integer',
            'institution_id' => 'nullable|integer',
        ]);

        $resolved = $this->assetService->resolveAsset(
            category: $validated['category'],
            assetCode: $validated['asset_code'] ?? null,
            brandId: !empty($validated['brand_id']) ? (int) $validated['brand_id'] : null,
            institutionId: !empty($validated['institution_id']) ? (int) $validated['institution_id'] : null
        );

        if (!$resolved) {
            return response()->json(['ok' => false, 'error' => 'Tidak ditemukan aset aktif untuk kriteria yang diminta.'], 404);
        }

        $transformed = $this->transformAsset($resolved);
        if ($request->boolean('include_data_uri', true)) {
            $transformed['data_uri'] = $this->assetService->getAssetAsBase64DataUri($resolved);
        }

        return response()->json([
            'ok'   => true,
            'data' => $transformed,
        ]);
    }

    protected function transformAsset(SystemAsset $asset): array
    {
        return [
            'id'                   => $asset->id,
            'uuid'                 => $asset->public_uuid,
            'name'                 => $asset->name,
            'asset_code'           => $asset->asset_code,
            'category'             => $asset->category,
            'scope'                => $asset->scope,
            'brand_id'             => $asset->brand_id,
            'brand_name'           => $asset->brand?->name,
            'institution_id'       => $asset->institution_id,
            'institution_name'     => $asset->institution?->name,
            'version'              => $asset->version,
            'is_default'           => $asset->is_default,
            'is_active'            => $asset->is_active,
            'is_image'             => $asset->is_image,
            'mime_type'            => $asset->mime_type,
            'formatted_size'       => $asset->formatted_size,
            'preview_url'          => route('admin.system-assets.preview', $asset),
            'drive_web_view_link'  => $asset->drive_web_view_link,
            'variable_placeholder' => $asset->variable_placeholder,
        ];
    }
}
