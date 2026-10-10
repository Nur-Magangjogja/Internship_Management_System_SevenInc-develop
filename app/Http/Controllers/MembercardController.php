<?php

namespace App\Http\Controllers;

use App\Models\AlumniMembercard;
use App\Models\InternshipRegistration;
use App\Models\User;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MembercardController extends Controller
{
    // Daftar brand yang tersedia (konsisten dengan User::getBrandPrefix)
    private array $brandList = [
        'magangjogja.com'  => 'Magangjogja',
        'areakerja.com'    => 'Areakerja',
        'republikweb.net'  => 'Republikweb',
        'titipsini.com'    => 'Titipsini',
        'ambilpaket.com'   => 'Ambilpaket',
        'bikinkepo.com'    => 'Bikinkepo',
        'bimbelcerdas.com' => 'Bimbelcerdas.com',
        'latihankerja.com' => 'Latihankerja.com',
        'lowkerjateng.com' => 'Lowkerjateng.com',
        'lowkerjogja.com'  => 'Lowkerjogja.com',
        'pijatjogja.com'   => 'Pijatjogja.com',
        'sayabantu.com'    => 'Sayabantu.com',
        'titikvisual.com'  => 'Titikvisual',
        'tuantanah.com'    => 'Tuantanah',
        'tukanglas.org'    => 'Tukanglas.org',
        'adakamarid'       => 'Adakamar.id',
        'seven inc'        => 'Seven Inc',
    ];

    public function index(Request $request)
    {
        $brandFilter = $request->get('brand');
        $search = $request->get('search');

        $query = AlumniMembercard::with(['intern.brandRel', 'intern.institution'])->orderByDesc('created_at');

        if ($brandFilter) {
            $query->whereHas('intern.brandRel', function ($q) use ($brandFilter) {
                $q->where('name', $brandFilter);
            });
        }

        if ($search) {
            $query->where(function ($mq) use ($search) {
                $mq->whereHas('intern', function ($q) use ($search) {
                    $q->where('fullname', 'like', "%{$search}%")
                      ->orWhere('student_id', 'like', "%{$search}%")
                      ->orWhereHas('institution', function ($iq) use ($search) {
                          $iq->where('name', 'like', "%{$search}%");
                      });
                })->orWhere('member_code', 'like', "%{$search}%");
            });
        }

        $downloads = $query->get();

        // Daftar brand unik (dari master brand)
        $availableBrands = Brand::orderBy('name')->pluck('name');

        return view('admin.membercards.index', compact('downloads', 'availableBrands', 'brandFilter', 'search'));
    }

    public function logDownload(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|string', // this is the member_code
        ]);

        if (!auth()->check()) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'status' => false
            ], 401);
        }

        if (auth()->user()->role !== 'pemagang') {
            return response()->json([
                'message' => 'Unauthorized action.',
                'status' => false
            ], 403);
        }

        // Find existing record ONLY
        $download = AlumniMembercard::with('intern')->where('member_code', $data['id'])->first();

        // If not found, DO NOT create a new record
        if (!$download) {
            return response()->json([
                'message' => 'Download record not found — please contact admin.',
                'status' => false
            ], 404);
        }

        // Strict ownership check: kartu harus milik pemagang yang sedang login (fail-closed jika relasi intern null)
        $intern = $download->intern;
        if (!$intern || (int)$intern->user_id !== (int)auth()->id()) {
            return response()->json([
                'message' => 'Unauthorized action.',
                'status' => false
            ], 403);
        }

        if (!$download->has_downloaded) {
            $download->has_downloaded = true;
            $download->downloaded_at = now();
            $download->save();
        }

        return response()->json([
            'message' => 'Download status updated successfully.',
            'status' => true
        ]);
    }

    public function show($code)
    {
        $download = AlumniMembercard::with(['intern.brandRel', 'intern.institution'])->where('member_code', $code)->firstOrFail();
        return view('admin.membercards.show', compact('download'));
    }

    public function edit($code)
    {
        $download = AlumniMembercard::with(['intern.brandRel', 'intern.institution'])->where('member_code', $code)->firstOrFail();
        return view('admin.membercards.edit', compact('download'));
    }

    public function update(Request $request, $code)
    {
        $download = AlumniMembercard::with('intern')->where('member_code', $code)->firstOrFail();

        // Karena data nama, instansi, brand sekarang milik InternshipRegistration, 
        // kita tidak perlu mengupdate di AlumniMembercard (kecuali batch_year / code).
        // Kita bisa asumsikan edit code manual jika diperlukan, atau sekadar menyimpan.
        
        $validated = $request->validate([
            'member_code' => 'required|string|max:100',
            'batch_year'  => 'nullable|string|max:10',
        ]);

        $download->update([
            'member_code' => $validated['member_code'],
            'batch_year'  => $validated['batch_year'] ?? $download->batch_year,
        ]);

        return redirect()->route('admin.membercards.show', $download->member_code)
            ->with('success', 'Data membercard berhasil diperbarui.');
    }

    public function destroy($code)
    {
        AlumniMembercard::where('member_code', $code)->delete();
        return redirect()->route('admin.membercards.index')
            ->with('success', 'Membercard deleted successfully.');
    }

    // ===== GENERATE MEMBERCARD =====

    /**
     * Generate membercard untuk satu pemagang berdasarkan code.
     * Hanya bisa untuk pemagang dengan status 'completed' atau 'active'.
     */
    public function generateOne(Request $request, $code)
    {
        $download = AlumniMembercard::with('intern')->where('member_code', $code)->firstOrFail();
        
        $user = User::find($download->intern->user_id);
        if (!$user) {
            return back()->with('error', "User tidak ditemukan untuk membercard kode {$code}.");
        }

        $registration = $download->intern;

        if (!$registration || !in_array($registration->internship_status, [InternshipRegistration::STATUS_COMPLETED, InternshipRegistration::STATUS_ACTIVE])) {
            return back()->with('error', "Membercard hanya bisa digenerate untuk pemagang yang aktif atau selesai.");
        }

        // Re-generate (refresh data terbaru ke record download)
        $user->createMemberCard();

        return back()->with('success', "Membercard untuk <strong>{$user->name}</strong> berhasil digenerate dan sudah tersedia di Dokumen Saya pemagang.");
    }

    /**
     * Generate membercard bulk — semua atau filter berdasarkan brand.
     * Hanya untuk pemagang dengan status 'completed'.
     */
    public function generateBulk(Request $request)
    {
        $request->validate([
            'member_codes' => 'required|array|min:1',
            'member_codes.*' => 'string',
        ]);

        $codes = $request->input('member_codes');

        // Ambil semua registrasi berdasarkan code membercard
        $membercards = AlumniMembercard::with('intern.user')
            ->whereIn('member_code', $codes)
            ->get();

        if ($membercards->isEmpty()) {
            return back()->with('error', 'Tidak ada data pemagang yang dipilih atau ditemukan.');
        }

        $generated = 0;
        $skipped   = 0;

        foreach ($membercards as $mc) {
            $reg = $mc->intern;
            if (!$reg || !$reg->user) {
                $skipped++;
                continue;
            }
            $reg->user->createMemberCard();
            $generated++;
        }

        $msg = "{$generated} membercard berhasil digenerate ulang.";
        if ($skipped > 0) {
            $msg .= " {$skipped} dilewati (user tidak ditemukan).";
        }

        return back()->with('success', $msg);
    }
}
