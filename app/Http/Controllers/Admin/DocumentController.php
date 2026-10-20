<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternshipRegistration;
use App\Models\InternLoa;
use App\Models\SklDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Tampilkan daftar LOA untuk pemagang yang sudah diterima
     */
    public function listLoas(Request $request)
    {
        // Ambil data pemagang yang sudah berstatus diterima/aktif/selesai (accepted, active, completed)
        $loas = InternshipRegistration::whereIn('internship_status', ['accepted', 'active', 'completed'])
            ->with(['user', 'loas'])
            ->latest('updated_at')
            ->paginate(10);

        // Transform data agar kompatibel dengan view yang ada
        $loas->getCollection()->transform(function ($reg) {
            $loa = new \stdClass();
            $loa->user = $reg->user;
            $loa->fullname = $reg->fullname;
            $loa->intern_id = $reg->id;
            $loa->file_url = null;

            // Cari rekam LOA yang pernah dibuat untuk pemagang ini
            $loaRecord = $reg->loas->sortByDesc('id')->first()
                ?? InternLoa::where('intern_id', $reg->id)->latest('id')->first();

            if ($loaRecord) {
                $safeLoaNumber = str_replace(['/', '\\'], '-', $loaRecord->loa_number);
                $safeName = Str::slug($reg->fullname ?? 'intern', '-');
                $fileName = $safeLoaNumber . '-' . $safeName . '.pdf';
                $path = 'documents/loa/' . $fileName;

                if (Storage::disk('local')->exists($path) || Storage::disk('public')->exists($path)) {
                    $loa->file_path = $path;
                } else {
                    $loa->file_path = 'loa_generated';
                }
                $loa->status = 'success';
                $loa->downloaded_at = $loaRecord->created_at ?? $reg->updated_at;
            } else {
                // Untuk status accepted / active / completed, dokumen LOA siap dibuat on the fly
                $loa->file_path = 'loa_generated';
                $loa->status = 'success';
                $loa->downloaded_at = $reg->updated_at;
            }

            return $loa;
        });

        return view('admin.documents.loas', compact('loas'));
    }

    /**
     * Tampilkan daftar SKL untuk pemagang yang sudah selesai (completed)
     */
    public function listSkls(Request $request)
    {
        // Ambil data pemagang yang sudah selesai (completed) untuk mencari SKL
        $skls = InternshipRegistration::where('internship_status', 'completed')
            ->with(['user', 'skls'])
            ->latest('updated_at')
            ->paginate(10);

        $skls->getCollection()->transform(function ($reg) {
            // Cek apakah data SKL sudah di-generate di database
            $sklRecord = $reg->skls->sortByDesc('id')->first()
                ?? SklDocument::where('intern_id', $reg->id)->latest('id')->first();

            $skl = new \stdClass();
            $skl->user = $reg->user;
            $skl->fullname = $reg->fullname;
            $skl->intern_id = $reg->id;
            $skl->file_url = null;

            if ($sklRecord) {
                // Return a route to download the SKL on the fly if needed
                $skl->file_path = 'skl_generated'; // Mark as generated
                $skl->status = 'success';
                $skl->downloaded_at = $sklRecord->created_at ?? $reg->updated_at;
            } else {
                $skl->file_path = null;
                $skl->status = null;
                $skl->downloaded_at = null;
            }

            return $skl;
        });

        return view('admin.documents.skls', compact('skls'));
    }
}
