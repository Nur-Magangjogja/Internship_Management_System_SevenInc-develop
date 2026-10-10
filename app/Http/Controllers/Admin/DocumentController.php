<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentDownload;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * Tampilkan daftar LOA yang diunduh
     */
    public function listLoas(Request $request)
    {
        // Ambil data LOA dari tabel intern_loas (database baru)
        $loas = \App\Models\InternLoa::with('intern.user')
            ->latest('created_at')
            ->paginate(10);

        // Transform data agar kompatibel dengan view yang ada
        $loas->getCollection()->transform(function ($loa) {
            $loa->user = $loa->intern->user ?? null;
            $loa->downloaded_at = $loa->created_at;

            $safeLoaNumber = str_replace('/', '-', $loa->loa_number);
            $safeName = \Illuminate\Support\Str::slug($loa->intern->fullname ?? 'intern', '-');
            $fileName = $safeLoaNumber . '-' . $safeName . '.pdf';
            $path = 'documents/loa/' . $fileName;

            if (\Illuminate\Support\Facades\Storage::disk('local')->exists($path) || \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                $loa->file_path = $path;
                $loa->status = 'success';
            } else {
                $loa->file_path = null;
                $loa->status = 'failed';
            }
            return $loa;
        });

        return view('admin.documents.loas', compact('loas'));
    }

    /**
     * Tampilkan daftar SKL yang diunduh
     */
    public function listSkls(Request $request)
    {
        // Ambil data pemagang yang sudah selesai (completed) untuk mencari SKL
        $skls = \App\Models\InternshipRegistration::where('internship_status', 'completed')
            ->with('user')
            ->latest('updated_at')
            ->paginate(10);

        $skls->getCollection()->transform(function ($reg) {
            // Cek apakah data SKL sudah di-generate di database
            $sklRecord = \App\Models\SklDocument::where('intern_id', $reg->id)->first();
            
            $skl = new \stdClass();
            $skl->user = $reg->user;
            $skl->downloaded_at = $reg->updated_at;
            $skl->file_url = null;
            
            if ($sklRecord) {
                // Return a route to download the SKL on the fly if needed
                // Format parameter based on the view route
                $skl->file_path = 'skl_generated'; // Mark as generated
                $skl->status = 'success';
                // Add the id so we can link to it later
                $skl->intern_id = $reg->id;
            } else {
                $skl->file_path = null;
                $skl->status = 'failed';
            }
            return $skl;
        });

        return view('admin.documents.skls', compact('skls'));
    }
}
