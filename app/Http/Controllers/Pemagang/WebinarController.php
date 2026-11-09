<?php

namespace App\Http\Controllers\Pemagang;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarAttendance;
use App\Models\InternshipRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebinarController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Daftar webinar aktif — hanya tampilkan webinar yang brand pemagang ini diizinkan.
     */
    public function index()
    {
        $user = auth()->user();

        // Ambil brand_id pemagang dari internship_registrations terbaru
        $registration = \App\Models\InternshipRegistration::with('brandRel')
            ->where('user_id', $user->id)
            ->whereNotNull('brand_id')
            ->latest()
            ->first();

        $internBrandRaw = $registration && $registration->brandRel ? $registration->brandRel->code : null;

        // Normalisasi brand ke kode (allowed_brands menyimpan kode, bukan nama lengkap)
        // internship_registrations bisa menyimpan nama lengkap ATAU kode, tangani keduanya
        $internBrand = Webinar::normalizeBrandCode($internBrandRaw);

        $webinars = Webinar::latest('event_date')
            ->get()
            ->filter(fn($webinar) => $webinar->isAllowedForBrand($internBrand))
            ->map(function ($webinar) use ($user) {
                $webinar->my_attendance = $webinar->attendanceOf($user->id);
                return $webinar;
            });

        return view('pemagang.webinars.index', compact('webinars'));
    }

    /**
     * Normalisasi brand ke kode (misal "Seven Inc" → "SI", "Magangjogja" → "MJ").
     * Kalau sudah kode, kembalikan apa adanya.
     */
    private function normalizeBrandCode(?string $brand): ?string
    {
        return Webinar::normalizeBrandCode($brand);
    }

    /**
     * Detail webinar + status kehadiran pemagang.
     */
    public function show(Webinar $webinar)
    {
        // Removed is_active check

        $user       = auth()->user();
        $attendance = $webinar->attendanceOf($user->id);

        return view('pemagang.webinars.show', compact('webinar', 'attendance'));
    }

    /**
     * Upload bukti kehadiran.
     */
    public function uploadProof(Request $request, Webinar $webinar)
    {
        $request->validate([
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'proof_note' => 'nullable|string|max:500',
        ], [
            'proof_file.required' => 'File bukti kehadiran wajib diupload.',
            'proof_file.mimes'    => 'Format file: JPG, PNG, atau PDF.',
            'proof_file.max'      => 'Ukuran file maksimal 5MB.',
        ]);

        $user = auth()->user();

        // Cek apakah sudah ada attendance sebelumnya
        $existing = WebinarAttendance::where('webinar_id', $webinar->id)
            ->where('user_id', $user->id)
            ->first();

        // Kalau sudah approved, tidak boleh submit ulang
        if ($existing && $existing->isApproved()) {
            return back()->with('error', 'Bukti kehadiran kamu sudah disetujui. Tidak bisa diubah.');
        }

        // Upload file
        $file     = $request->file('proof_file');
        $safeName = Str::slug($user->name, '_');
        $filename = "webinar_{$webinar->id}_{$safeName}_" . now()->format('Ymd_His') . '.' . $file->getClientOriginalExtension();
        $path     = $file->storeAs('webinar-proofs', $filename, 'public');

        // Hapus file lama kalau ada
        if ($existing && $existing->proof_file) {
            Storage::disk('public')->delete($existing->proof_file);
        }

        if ($existing) {
            // Update (re-submit setelah rejected)
            $existing->update([
                'proof_file' => $path,
                'proof_note' => $request->proof_note,
                'status'     => WebinarAttendance::STATUS_PENDING,
                'rejection_reason' => null,
                'reviewed_by'      => null,
                'reviewed_at'      => null,
                'certificate_id'   => null,
            ]);
        } else {
            // Buat baru
            WebinarAttendance::create([
                'webinar_id' => $webinar->id,
                'user_id'    => $user->id,
                'proof_file' => $path,
                'proof_note' => $request->proof_note,
                'status'     => WebinarAttendance::STATUS_PENDING,
            ]);
        }

        return back()->with('success', '✅ Bukti kehadiran berhasil dikirim! Admin akan meninjau dan memberikan sertifikat jika disetujui.');
    }
}
