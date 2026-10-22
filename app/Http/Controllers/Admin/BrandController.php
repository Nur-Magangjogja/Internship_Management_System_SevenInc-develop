<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandController extends Controller
{
    public function index()
    {
        $brands = Brand::all();
        return view('admin.brands.index', compact('brands'));
    }

    public function create()
    {
        return view('admin.brands.create');
    }

    public function store(Request $request)
    {
        $messages = [
            'code.required'                 => 'Kode Brand wajib diisi.',
            'code.unique'                   => 'Kode Brand ini sudah digunakan.',
            'name.required'                 => 'Nama Brand wajib diisi.',
            'logo.max'                      => 'Ukuran file Logo Brand tidak boleh melebihi 2MB.',
            'signature.max'                 => 'Ukuran file Tanda Tangan tidak boleh melebihi 2MB.',
            'stamp.max'                     => 'Ukuran file Stempel Perusahaan tidak boleh melebihi 2MB.',
            'internship_certificate_bg.max' => 'Ukuran file Background Sertifikat Magang tidak boleh melebihi 2MB.',
            'webinar_certificate_bg.max'    => 'Ukuran file Background Sertifikat Webinar tidak boleh melebihi 2MB.',
            'membercard_bg.max'             => 'Ukuran file Background Membercard Digital tidak boleh melebihi 3MB.',
            'logo.image'                    => 'Berkas Logo harus berupa gambar yang valid.',
            'signature.image'               => 'Berkas Tanda Tangan harus berupa gambar yang valid.',
            'stamp.image'                   => 'Berkas Stempel harus berupa gambar yang valid.',
            'internship_certificate_bg.image' => 'Berkas Background Magang harus berupa gambar yang valid.',
            'webinar_certificate_bg.image'  => 'Berkas Background Webinar harus berupa gambar yang valid.',
            'membercard_bg.image'           => 'Berkas Background Membercard harus berupa gambar yang valid.',
        ];

        $request->validate([
            'code' => 'required|string|max:50|unique:brands,code',
            'name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'internship_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'webinar_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'membercard_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
            'signature' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'stamp' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], $messages);

        $fileFields = ['logo', 'internship_certificate_bg', 'webinar_certificate_bg', 'membercard_bg', 'signature', 'stamp'];
        $data = $request->except($fileFields);

        foreach ($fileFields as $fileField) {
            if ($request->hasFile($fileField)) {
                $path = $request->file($fileField)->store('brands', 'public');
                $data[$fileField] = $path;
            }
        }

        $brand = Brand::create($data);

        try {
            app(\App\Services\SystemAssetService::class)->syncBrandAssets($brand, \Illuminate\Support\Facades\Auth::user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Gagal sinkronisasi aset brand ke SystemAsset: " . $e->getMessage());
        }

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil ditambahkan.');
    }

    public function show(Brand $brand)
    {
        return view('admin.brands.show', compact('brand'));
    }

    public function edit(Brand $brand)
    {
        return view('admin.brands.edit', compact('brand'));
    }

    public function update(Request $request, Brand $brand)
    {
        $messages = [
            'code.required'                 => 'Kode Brand wajib diisi.',
            'code.unique'                   => 'Kode Brand ini sudah digunakan.',
            'name.required'                 => 'Nama Brand wajib diisi.',
            'logo.max'                      => 'Ukuran file Logo Brand tidak boleh melebihi 2MB.',
            'signature.max'                 => 'Ukuran file Tanda Tangan tidak boleh melebihi 2MB.',
            'stamp.max'                     => 'Ukuran file Stempel Perusahaan tidak boleh melebihi 2MB.',
            'internship_certificate_bg.max' => 'Ukuran file Background Sertifikat Magang tidak boleh melebihi 2MB.',
            'webinar_certificate_bg.max'    => 'Ukuran file Background Sertifikat Webinar tidak boleh melebihi 2MB.',
            'membercard_bg.max'             => 'Ukuran file Background Membercard Digital tidak boleh melebihi 3MB.',
            'logo.image'                    => 'Berkas Logo harus berupa gambar yang valid.',
            'signature.image'               => 'Berkas Tanda Tangan harus berupa gambar yang valid.',
            'stamp.image'                   => 'Berkas Stempel harus berupa gambar yang valid.',
            'internship_certificate_bg.image' => 'Berkas Background Magang harus berupa gambar yang valid.',
            'webinar_certificate_bg.image'  => 'Berkas Background Webinar harus berupa gambar yang valid.',
            'membercard_bg.image'           => 'Berkas Background Membercard harus berupa gambar yang valid.',
        ];

        $request->validate([
            'code' => 'required|string|max:50|unique:brands,code,' . $brand->id,
            'name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'internship_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'webinar_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'membercard_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
            'signature' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'stamp' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], $messages);

        $fileFields = ['logo', 'internship_certificate_bg', 'webinar_certificate_bg', 'membercard_bg', 'signature', 'stamp'];
        $removeFields = array_map(fn($f) => 'remove_' . $f, $fileFields);
        $data = $request->except(array_merge($fileFields, $removeFields));

        foreach ($fileFields as $fileField) {
            if ($request->hasFile($fileField)) {
                // Addendum Final V4: Jangan langsung delete file lama agar issued document snapshots & histori tetap utuh
                $path = $request->file($fileField)->store('brands', 'public');
                $data[$fileField] = $path;
            } elseif ($request->boolean('remove_' . $fileField) || $request->input('remove_' . $fileField) === '1') {
                $data[$fileField] = null;
            }
        }

        $brand->update($data);

        try {
            app(\App\Services\SystemAssetService::class)->syncBrandAssets($brand, \Illuminate\Support\Facades\Auth::user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Gagal sinkronisasi aset brand ke SystemAsset: " . $e->getMessage());
        }

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil diperbarui.');
    }

    public function destroy(Brand $brand)
    {
        // Addendum Final V4: Pertahankan berkas fisik aset untuk integritas snapshot dokumen terbit
        $brand->delete();
        
        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil dihapus.');
    }
}
