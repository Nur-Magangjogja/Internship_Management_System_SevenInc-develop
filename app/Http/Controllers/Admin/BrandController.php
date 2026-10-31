<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use DomainException;

class BrandController extends Controller
{
    public function index()
    {
        $brands = Brand::with('divisions')->orderBy('name')->get();
        return view('admin.brands.index', compact('brands'));
    }

    public function create()
    {
        $divisions = \App\Models\Division::active()->get();
        return view('admin.brands.create', compact('divisions'));
    }

    public function store(Request $request)
    {
        $this->normalizeAndValidateDomainInputs($request);

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
            'registration_host' => 'nullable|string|max:150',
            'primary_domain' => 'nullable|string|max:150',
            'division_mode' => 'sometimes|required|in:global,brand_specific',
            'division_ids' => 'nullable|array',
            'division_ids.*' => 'exists:divisions,id',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'internship_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'webinar_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'membercard_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
            'signature' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'stamp' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], $messages);

        $fileFields = ['logo', 'internship_certificate_bg', 'webinar_certificate_bg', 'membercard_bg', 'signature', 'stamp'];
        $data = $request->except(array_merge($fileFields, ['division_ids', 'division_ids_present', '_token', '_method']));
        unset($data['is_default'], $data['lifecycle_status'], $data['domain_verification_token'], $data['domain_verified_at'], $data['domain_verification_method']);
        $data['division_mode'] = $request->input('division_mode', Brand::DIVISION_MODE_BRAND_SPECIFIC);
        $data['lifecycle_status'] = Brand::STATUS_DRAFT;
        $data['is_default'] = false;
        $data['domain_verification_token'] = (!empty($data['registration_host']) && !empty($data['primary_domain'])) ? Str::random(48) : null;
        $data['domain_verified_at'] = null;
        $data['domain_verification_method'] = null;

        foreach ($fileFields as $fileField) {
            if ($request->hasFile($fileField)) {
                $path = $request->file($fileField)->store('brands', 'public');
                $data[$fileField] = $path;
            }
        }

        $brand = DB::transaction(function () use ($data, $request) {
            $brand = Brand::create($data);
            $brand->divisions()->sync($request->input('division_ids', []));
            return $brand;
        });

        try {
            app(\App\Services\SystemAssetService::class)->syncBrandAssets($brand, \Illuminate\Support\Facades\Auth::user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Gagal sinkronisasi aset brand ke SystemAsset: " . $e->getMessage());
        }

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil ditambahkan.');
    }

    public function show(Brand $brand)
    {
        $brand->load('divisions');
        return view('admin.brands.show', compact('brand'));
    }

    public function edit(Brand $brand)
    {
        $brand->load('divisions');
        $divisions = \App\Models\Division::active()->get();
        return view('admin.brands.edit', compact('brand', 'divisions'));
    }

    public function update(Request $request, Brand $brand)
    {
        $domainInputsSubmitted = $request->exists('registration_host') || $request->exists('primary_domain');
        $this->normalizeAndValidateDomainInputs($request, $brand);

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
            'registration_host' => 'nullable|string|max:150',
            'primary_domain' => 'nullable|string|max:150',
            'division_mode' => 'sometimes|required|in:global,brand_specific',
            'division_ids' => 'nullable|array',
            'division_ids.*' => 'exists:divisions,id',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'internship_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'webinar_certificate_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'membercard_bg' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
            'signature' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'stamp' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], $messages);

        $fileFields = ['logo', 'internship_certificate_bg', 'webinar_certificate_bg', 'membercard_bg', 'signature', 'stamp'];
        $removeFields = array_map(fn($f) => 'remove_' . $f, $fileFields);
        $data = $request->except(array_merge($fileFields, $removeFields, ['division_ids', 'division_ids_present', '_token', '_method']));
        unset($data['is_default'], $data['lifecycle_status'], $data['domain_verification_token'], $data['domain_verified_at'], $data['domain_verification_method']);

        $domainChanged = $domainInputsSubmitted && (
            $brand->registration_host !== $request->input('registration_host')
            || $brand->primary_domain !== $request->input('primary_domain')
        );
        $currentDivisionIds = $brand->divisions()->pluck('divisions.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        // The explicit hidden marker distinguishes "all checkboxes unchecked" (clear the mapping)
        // from API callers that simply omit division fields (preserve the existing mapping).
        $divisionIdsSubmitted = $request->boolean('division_ids_present') || $request->exists('division_ids');
        $requestedDivisionIds = $divisionIdsSubmitted
            ? collect($request->input('division_ids', []))->map(fn ($id) => (int) $id)->sort()->values()->all()
            : $currentDivisionIds;

        // Rekonsiliasi division_mode: jika mode tidak dikirim eksplisit tetapi ada divisi khusus yang dipilih,
        // atau jika mode di database masih null, set sebagai brand_specific agar relasi pivot terikat resmi.
        if (!$request->filled('division_mode')) {
            if ($divisionIdsSubmitted && !empty($requestedDivisionIds)) {
                $data['division_mode'] = Brand::DIVISION_MODE_BRAND_SPECIFIC;
            }
        }

        $effectiveDivisionMode = $data['division_mode'] ?? $brand->division_mode;
        $readinessConfigurationChanged = $domainChanged
            || ($effectiveDivisionMode !== $brand->division_mode)
            || ($divisionIdsSubmitted && $currentDivisionIds !== $requestedDivisionIds);

        if ($domainInputsSubmitted) {
            $data['registration_host'] = $request->input('registration_host');
            $data['primary_domain'] = $request->input('primary_domain');
        }

        if ($domainChanged) {
            $hasDomainPair = !empty($data['registration_host']) && !empty($data['primary_domain']);
            $data['domain_verification_token'] = $hasDomainPair ? Str::random(48) : null;
            $data['domain_verified_at'] = null;
            $data['domain_verification_method'] = null;
        } elseif (!$brand->domain_verified_at
            && empty($brand->domain_verification_token)
            && !empty($brand->registration_host)
            && !empty($brand->primary_domain)) {
            $data['domain_verification_token'] = Str::random(48);
        }

        if ($readinessConfigurationChanged && in_array($brand->lifecycle_status, [Brand::STATUS_ACTIVE, Brand::STATUS_READY, Brand::STATUS_DRAFT], true)) {
            $data['lifecycle_status'] = Brand::STATUS_CONFIGURATION_PENDING;
        }

        foreach ($fileFields as $fileField) {
            if ($request->hasFile($fileField)) {
                // Addendum Final V4: Jangan langsung delete file lama agar issued document snapshots & histori tetap utuh
                $path = $request->file($fileField)->store('brands', 'public');
                $data[$fileField] = $path;
            } elseif ($request->boolean('remove_' . $fileField) || $request->input('remove_' . $fileField) === '1') {
                $data[$fileField] = null;
            }
        }

        DB::transaction(function () use ($brand, $data, $request, $divisionIdsSubmitted, $requestedDivisionIds) {
            $brand->update($data);
            if ($divisionIdsSubmitted) {
                $brand->divisions()->sync($requestedDivisionIds);
            }
        });

        try {
            app(\App\Services\SystemAssetService::class)->syncBrandAssets($brand, \Illuminate\Support\Facades\Auth::user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Gagal sinkronisasi aset brand ke SystemAsset: " . $e->getMessage());
        }

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil diperbarui.');
    }

    public function setDefault(Brand $brand)
    {
        if (!$brand->isRegistrationHostActive()) {
            return back()->with('error', 'Brand default harus berstatus Active dan domainnya sudah diverifikasi.');
        }

        $brand->makeDefault();
        return back()->with('success', "Brand \"{$brand->name}\" berhasil ditetapkan sebagai Brand Default sistem.");
    }

    public function verifyDomain(Brand $brand, \App\Services\BrandDomainVerificationService $verification)
    {
        if ($brand->lifecycle_status === Brand::STATUS_ARCHIVED) {
            return back()->with('error', 'Brand yang sudah diarsipkan tidak dapat diverifikasi atau diaktifkan kembali melalui alur ini.');
        }

        if (empty($brand->primary_domain) || empty($brand->domain_verification_token)) {
            return back()->with('error', 'Domain belum lengkap atau token verifikasi belum tersedia. Simpan konfigurasi domain terlebih dahulu.');
        }

        if (!$verification->verify($brand->primary_domain, $brand->domain_verification_token)) {
            return back()->with('error', 'TXT verifikasi belum ditemukan atau nilainya tidak cocok. Periksa DNS lalu coba kembali.');
        }

        $brand->domain_verified_at = now();
        $brand->domain_verification_method = 'dns_txt';
        if ($brand->lifecycle_status === Brand::STATUS_DRAFT) {
            $brand->lifecycle_status = Brand::STATUS_CONFIGURATION_PENDING;
        }
        $brand->save();

        return back()->with('success', 'Domain berhasil diverifikasi. Jalankan pemeriksaan kesiapan sebelum mengaktifkan brand.');
    }

    public function checkReadiness(Brand $brand, \App\Services\BrandReadinessService $readiness)
    {
        if ($brand->lifecycle_status === Brand::STATUS_ARCHIVED) {
            return back()->with('error', 'Brand archived tidak dapat diaktifkan kembali melalui Readiness Gate biasa.');
        }

        $result = $readiness->assess($brand);
        if (!$result['ready']) {
            if (!in_array($brand->lifecycle_status, [Brand::STATUS_ACTIVE, Brand::STATUS_SUSPENDED], true)) {
                $brand->lifecycle_status = Brand::STATUS_CONFIGURATION_PENDING;
                $brand->save();
            }

            return back()->with('error', 'Brand belum siap: ' . implode(' ', $result['blockers']));
        }

        if (!in_array($brand->lifecycle_status, [Brand::STATUS_ACTIVE, Brand::STATUS_SUSPENDED], true)) {
            $brand->lifecycle_status = Brand::STATUS_READY;
            $brand->save();
        }

        if ($brand->lifecycle_status === Brand::STATUS_SUSPENDED) {
            return back()->with('success', 'Pemeriksaan Readiness Gate lulus. Brand siap untuk direaktivasi (Cabut Suspend).');
        }

        return back()->with('success', 'Readiness Gate lulus. Brand siap diaktifkan.');
    }

    public function activate(Brand $brand, \App\Services\BrandReadinessService $readiness)
    {
        if ($brand->lifecycle_status === Brand::STATUS_ACTIVE && $brand->isRegistrationHostActive()) {
            return back()->with('success', 'Brand sudah aktif.');
        }

        if ($brand->lifecycle_status !== Brand::STATUS_READY) {
            return back()->with('error', 'Brand harus melewati pemeriksaan Readiness Gate dan berstatus Ready terlebih dahulu.');
        }

        $result = $readiness->assess($brand);
        if (!$result['ready']) {
            $brand->lifecycle_status = Brand::STATUS_CONFIGURATION_PENDING;
            $brand->save();
            return back()->with('error', 'Aktivasi dibatalkan karena kesiapan berubah: ' . implode(' ', $result['blockers']));
        }

        $brand->lifecycle_status = Brand::STATUS_ACTIVE;
        $brand->save();

        return back()->with('success', 'Brand berhasil diaktifkan dan host masuk ke registry tepercaya.');
    }

    public function unsuspend(Brand $brand, \App\Services\BrandReadinessService $readiness)
    {
        if ($brand->lifecycle_status !== Brand::STATUS_SUSPENDED) {
            return back()->with('error', 'Hanya brand berstatus Suspended yang dapat direaktivasi.');
        }

        $result = $readiness->assess($brand);
        if (!$result['ready']) {
            return back()->with('error', 'Brand belum dapat direaktivasi karena belum siap: ' . implode(' ', $result['blockers']));
        }

        $brand->lifecycle_status = Brand::STATUS_ACTIVE;
        $brand->save();

        return back()->with('success', 'Penangguhan brand berhasil dicabut. Brand kembali aktif dan host masuk ke registry tepercaya.');
    }

    public function suspend(Brand $brand)
    {
        if ($brand->is_default) {
            return back()->with('error', 'Brand default tidak dapat disuspend. Tetapkan brand default pengganti terlebih dahulu.');
        }
        if ($brand->lifecycle_status === Brand::STATUS_ARCHIVED) {
            return back()->with('error', 'Brand yang sudah diarsipkan tidak dapat disuspend.');
        }

        $brand->lifecycle_status = Brand::STATUS_SUSPENDED;
        $brand->save();
        return back()->with('success', 'Brand disuspend. Host terkait langsung dikeluarkan dari registry tepercaya.');
    }

    public function archive(Brand $brand)
    {
        if ($brand->is_default) {
            return back()->with('error', 'Brand default tidak dapat diarsipkan sebelum brand default pengganti ditetapkan.');
        }

        $brand->lifecycle_status = Brand::STATUS_ARCHIVED;
        $brand->save();
        return back()->with('success', 'Brand diarsipkan tanpa menghapus riwayat data.');
    }

    public function destroy(Brand $brand)
    {
        try {
            DB::transaction(function () use ($brand) {
                /** @var Brand $lockedBrand */
                $lockedBrand = Brand::query()
                    ->whereKey($brand->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedBrand->is_default) {
                    throw new DomainException(
                        'Brand default tidak dapat dihapus. Tetapkan brand default pengganti terlebih dahulu.'
                    );
                }

                $dependencies = $this->brandDependencyLabels($lockedBrand->id);
                if ($dependencies !== []) {
                    throw new DomainException(
                        'Brand tidak dapat dihapus karena masih memiliki data terkait: '
                        . implode(', ', $dependencies)
                        . '. Pertahankan record ini atau pindahkan data melalui proses migrasi yang terencana.'
                    );
                }

                // Hanya brand yang bukan default dan tidak memiliki data terkait yang boleh dihapus fisik.
                // File aset fisik tidak dihapus agar snapshot dokumen yang telah terbit tetap utuh.
                $lockedBrand->delete();
            });
        } catch (DomainException $e) {
            return redirect()
                ->route('admin.brands.index')
                ->with('error', $e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            // Pertahanan tambahan untuk FK yang belum tercakup dalam daftar dependensi di atas.
            \Illuminate\Support\Facades\Log::warning('Penghapusan brand ditolak oleh constraint database.', [
                'brand_id' => $brand->id,
                'exception' => get_class($e),
            ]);

            return redirect()
                ->route('admin.brands.index')
                ->with('error', 'Brand tidak dapat dihapus karena masih memiliki relasi data yang dilindungi.');
        }

        return redirect()->route('admin.brands.index')->with('success', 'Brand berhasil dihapus.');
    }

    /** Normalize user-provided domains and enforce cross-column uniqueness. */
    private function normalizeAndValidateDomainInputs(Request $request, ?Brand $brand = null): void
    {
        $normalizer = app(\App\Services\BrandDomainInputService::class);
        $domains = [];
        foreach (['registration_host', 'primary_domain'] as $field) {
            $raw = $request->exists($field)
                ? $request->input($field)
                : ($brand?->{$field});

            if ($raw !== null && !is_string($raw)) {
                throw ValidationException::withMessages([
                    $field => 'Nilai domain harus berupa teks hostname yang valid.',
                ]);
            }

            try {
                $domains[$field] = $normalizer->normalize(is_string($raw) ? $raw : null);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$field => $exception->getMessage()]);
            }
        }

        if (($domains['registration_host'] === null) !== ($domains['primary_domain'] === null)) {
            throw ValidationException::withMessages([
                'registration_host' => 'Host pendaftaran dan domain utama harus diisi bersama-sama.',
                'primary_domain' => 'Domain utama wajib diisi jika host pendaftaran diisi.',
            ]);
        }

        if (!$normalizer->registrationHostBelongsToPrimaryDomain($domains['registration_host'], $domains['primary_domain'])) {
            throw ValidationException::withMessages([
                'registration_host' => 'Host pendaftaran harus sama dengan domain utama atau merupakan subdomain dari domain utama.',
            ]);
        }

        $request->merge($domains);
        $values = array_values(array_unique(array_filter($domains)));
        if ($values === []) {
            return;
        }

        $conflictQuery = Brand::query()->where(function ($query) use ($values): void {
            foreach ($values as $value) {
                $query->orWhereRaw('LOWER(registration_host) = ?', [$value])
                    ->orWhereRaw('LOWER(primary_domain) = ?', [$value]);
            }
        });

        if ($brand) {
            $conflictQuery->where('id', '!=', $brand->getKey());
        }

        if ($conflictQuery->exists()) {
            throw ValidationException::withMessages([
                'registration_host' => 'Host atau domain tersebut sudah terdaftar pada brand lain.',
                'primary_domain' => 'Host atau domain tersebut sudah digunakan oleh brand lain.',
            ]);
        }
    }

    /**
     * Mengembalikan label tabel yang masih memiliki data dengan brand_id ini.
     * Pemeriksaan tabel/kolom membuat guard tetap aman pada lingkungan dengan migrasi opsional.
     */
    private function brandDependencyLabels(int $brandId): array
    {
        $references = [
            'webinars' => 'webinar',
            'internship_registrations' => 'registrasi magang',
            'users' => 'akun pengguna',
            'spreadsheet_connections' => 'koneksi spreadsheet',
            'system_assets' => 'aset sistem',
            'mous' => 'dokumen MoU',
            'intern_extras' => 'data tambahan pemagang',
        ];

        $dependencies = [];
        foreach ($references as $table => $label) {
            if (
                Schema::hasTable($table)
                && Schema::hasColumn($table, 'brand_id')
                && DB::table($table)->where('brand_id', $brandId)->exists()
            ) {
                $dependencies[] = $label;
            }
        }

        return $dependencies;
    }
}
