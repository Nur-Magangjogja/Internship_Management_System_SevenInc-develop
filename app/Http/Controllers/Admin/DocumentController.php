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
     * Tampilkan daftar LOA yang diunduh beserta status pipeline sinkronisasi cloud.
     */
    public function listLoas(Request $request)
    {
        $loas = InternLoa::with(['intern.user', 'intern.brandRel'])
            ->latest('id')
            ->paginate(15);

        $loas->getCollection()->transform(function ($loa) {
            $loa->user = $loa->intern?->user ?? null;
            $loa->downloaded_at = $loa->created_at;

            // Cari GeneratedDocument terkait
            $genDoc = GeneratedDocument::where('internship_registration_id', $loa->intern_id)
                ->where('document_type', GeneratedDocument::TYPE_LOA)
                ->latest('id')
                ->first();

            $loa->generated_doc = $genDoc;

            if ($genDoc) {
                $loa->file_path           = $genDoc->file_path;
                $loa->pipeline_status     = $genDoc->status;
                $loa->drive_file_id       = $genDoc->drive_file_id;
                $loa->drive_web_view_link = $genDoc->drive_web_view_link;
                $loa->public_uuid         = $genDoc->public_uuid;
                $loa->status              = in_array($genDoc->status, [GeneratedDocument::STATUS_GENERATED, GeneratedDocument::STATUS_SYNC_PENDING, GeneratedDocument::STATUS_SYNCED]) ? 'success' : 'failed';
            } else {
                $safeLoaNumber = str_replace('/', '-', $loa->loa_number);
                $safeName = Str::slug($loa->intern->fullname ?? 'intern', '-');
                $fileName = $safeLoaNumber . '-' . $safeName . '.pdf';
                $path = 'documents/loa/' . $fileName;

                $fileExists = Storage::disk('local')->exists($path) || Storage::disk('public')->exists($path);
                $loa->file_path       = $fileExists ? $path : null;
                $loa->pipeline_status = $fileExists ? GeneratedDocument::STATUS_GENERATED : GeneratedDocument::STATUS_FAILED;
                $loa->status          = $fileExists ? 'success' : 'failed';
            }

            return $loa;
        });

        return view('admin.documents.loas', compact('loas'));
    }

    /**
     * Tampilkan daftar SKL yang diunduh beserta status pipeline sinkronisasi cloud.
     */
    public function listSkls(Request $request)
    {
        $skls = InternshipRegistration::where('internship_status', InternshipRegistration::STATUS_COMPLETED)
            ->with(['user', 'brandRel'])
            ->latest('updated_at')
            ->paginate(15);

        $skls->getCollection()->transform(function ($reg) {
            $sklRecord = SklDocument::where('intern_id', $reg->id)->first();
            $genDoc    = GeneratedDocument::where('internship_registration_id', $reg->id)
                ->where('document_type', GeneratedDocument::TYPE_SKL)
                ->latest('id')
                ->first();

            $skl = new \stdClass();
            $skl->user          = $reg->user;
            $skl->intern_name   = $reg->fullname;
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
            } elseif ($sklRecord) {
                $skl->file_path       = 'skl_generated';
                $skl->pipeline_status = GeneratedDocument::STATUS_GENERATED;
                $skl->status          = 'success';
            } else {
                $skl->file_path       = null;
                $skl->pipeline_status = GeneratedDocument::STATUS_FAILED;
                $skl->status          = 'failed';
            }

            return $skl;
        });

        return view('admin.documents.skls', compact('skls'));
    }
}
