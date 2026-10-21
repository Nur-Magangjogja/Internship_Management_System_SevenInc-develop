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
        $request->validate([
            'code' => 'required|string|max:50|unique:brands,code',
            'name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'internship_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'webinar_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'membercard_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
            'signature' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $fileFields = ['logo', 'internship_certificate_bg', 'webinar_certificate_bg', 'membercard_bg', 'signature'];
        $data = $request->except($fileFields);

        foreach ($fileFields as $fileField) {
            if ($request->hasFile($fileField)) {
                $path = $request->file($fileField)->store('brands', 'public');
                $data[$fileField] = $path;
            }
        }

        Brand::create($data);

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
        $request->validate([
            'code' => 'required|string|max:50|unique:brands,code,' . $brand->id,
            'name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'internship_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'webinar_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'membercard_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
            'signature' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $fileFields = ['logo', 'internship_certificate_bg', 'webinar_certificate_bg', 'membercard_bg', 'signature'];
        $data = $request->except($fileFields);

        foreach ($fileFields as $fileField) {
            if ($request->hasFile($fileField)) {
                // Addendum Final V4: Jangan langsung delete file lama agar issued document snapshots & histori tetap utuh
                $path = $request->file($fileField)->store('brands', 'public');
                $data[$fileField] = $path;
            }
        }

        $brand->update($data);

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil diperbarui.');
    }

    public function destroy(Brand $brand)
    {
        // Addendum Final V4: Pertahankan berkas fisik aset untuk integritas snapshot dokumen terbit
        $brand->delete();
        
        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil dihapus.');
    }
}
