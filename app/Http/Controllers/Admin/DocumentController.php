<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Models\InternLoa;
use App\Models\InternshipRegistration;
use App\Models\SklDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Tampilkan daftar LOA untuk pemagang yang sudah diterima beserta status pipeline sinkronisasi cloud.
     */
    public function listLoas(Request $request)
    {
        // Ambil data pemagang yang sudah berstatus diterima/aktif/selesai (accepted, active, completed)
        $loas = InternshipRegistration::whereIn('internship_status', ['accepted', 'active', 'completed'])
            ->with(['user', 'brandRel', 'loas'])
            ->latest('updated_at')
            ->paginate(15);

        // Transform data agar kompatibel dengan view yang ada
        $loas->getCollection()->transform(function ($reg) {
            $loa = new \stdClass();
            $loa->user = $reg->user;
            $loa->fullname = $reg->fullname;
            $loa->intern_id = $reg->id;
            $loa->file_url = null;

            // 1. Cek GeneratedDocument (pipeline sinkronisasi cloud)
            $genDoc = GeneratedDocument::where('internship_registration_id', $reg->id)
                ->where('document_type', GeneratedDocument::TYPE_LOA)
                ->latest('id')
                ->first();

            $loa->generated_doc = $genDoc;

            // 2. Cek rekam LOA yang pernah dibuat untuk pemagang ini
            $loaRecord = $reg->loas?->sortByDesc('id')->first()
                ?? InternLoa::where('intern_id', $reg->id)->latest('id')->first();

            if ($genDoc) {
                $loa->file_path           = $genDoc->file_path;
                $loa->pipeline_status     = $genDoc->status;
                $loa->drive_file_id       = $genDoc->drive_file_id;
                $loa->drive_web_view_link = $genDoc->drive_web_view_link;
                $loa->public_uuid         = $genDoc->public_uuid;
                $loa->status              = in_array($genDoc->status, [GeneratedDocument::STATUS_GENERATED, GeneratedDocument::STATUS_SYNC_PENDING, GeneratedDocument::STATUS_SYNCED]) ? 'success' : 'failed';
                $loa->downloaded_at       = $genDoc->created_at ?? $reg->updated_at;
            } elseif ($loaRecord) {
                $safeLoaNumber = str_replace(['/', '\\'], '-', $loaRecord->loa_number);
                $safeName = Str::slug($reg->fullname ?? 'intern', '-');
                $fileName = $safeLoaNumber . '-' . $safeName . '.pdf';
                $path = 'documents/loa/' . $fileName;

                if (Storage::disk('local')->exists($path) || Storage::disk('public')->exists($path)) {
                    $loa->file_path = $path;
                } else {
                    $loa->file_path = 'loa_generated';
                }
                $loa->pipeline_status = GeneratedDocument::STATUS_GENERATED;
                $loa->status          = 'success';
                $loa->downloaded_at   = $loaRecord->created_at ?? $reg->updated_at;
            } else {
                // Untuk status accepted / active / completed, dokumen LOA siap dibuat on the fly
                $loa->file_path       = 'loa_generated';
                $loa->pipeline_status = GeneratedDocument::STATUS_GENERATED;
                $loa->status          = 'success';
                $loa->downloaded_at   = $reg->updated_at;
            }

            return $loa;
        });

        return view('admin.documents.loas', compact('loas'));
    }

    /**
     * Tampilkan daftar SKL untuk pemagang yang sudah selesai (completed) beserta status pipeline sinkronisasi cloud.
     */
    public function listSkls(Request $request)
    {
        // Ambil data pemagang yang sudah selesai (completed) untuk mencari SKL
        $skls = InternshipRegistration::where('internship_status', InternshipRegistration::STATUS_COMPLETED)
            ->with(['user', 'brandRel', 'skls'])
            ->latest('updated_at')
            ->paginate(15);

        $skls->getCollection()->transform(function ($reg) {
            $genDoc = GeneratedDocument::where('internship_registration_id', $reg->id)
                ->where('document_type', GeneratedDocument::TYPE_SKL)
                ->latest('id')
                ->first();

            $sklRecord = $reg->skls?->sortByDesc('id')->first()
                ?? SklDocument::where('intern_id', $reg->id)->latest('id')->first();

            $skl = new \stdClass();
            $skl->user          = $reg->user;
            $skl->intern_name   = $reg->fullname;
            $skl->fullname      = $reg->fullname;
            $skl->downloaded_at = $reg->updated_at;
            $skl->file_url      = null;
            $skl->intern_id     = $reg->id;
            $skl->generated_doc = $genDoc;

            if ($genDoc) {
                $skl->file_path           = $genDoc->file_path;
                $skl->pipeline_status     = $genDoc->status;
                $skl->drive_file_id       = $genDoc->drive_file_id;
                $skl->drive_web_view_link = $genDoc->drive_web_view_link;
                $skl->public_uuid         = $genDoc->public_uuid;
                $skl->status              = in_array($genDoc->status, [GeneratedDocument::STATUS_GENERATED, GeneratedDocument::STATUS_SYNC_PENDING, GeneratedDocument::STATUS_SYNCED]) ? 'success' : 'failed';
                $skl->downloaded_at       = $genDoc->created_at ?? $reg->updated_at;
            } elseif ($sklRecord) {
                $skl->file_path       = 'skl_generated';
                $skl->pipeline_status = GeneratedDocument::STATUS_GENERATED;
                $skl->status          = 'success';
                $skl->downloaded_at   = $sklRecord->created_at ?? $reg->updated_at;
            } else {
                $skl->file_path       = null;
                $skl->pipeline_status = GeneratedDocument::STATUS_FAILED;
                $skl->status          = null;
                $skl->downloaded_at   = null;
            }

            return $skl;
        });

        return view('admin.documents.skls', compact('skls'));
    }
}
