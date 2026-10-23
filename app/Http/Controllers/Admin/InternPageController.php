<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InternshipRegistration as IR;
use App\Models\Division;
use App\Models\Brand;
use Illuminate\Support\Facades\View;

class InternPageController extends Controller
{
    protected function renderIndex(array $data)
    {
        $default = [
            'divisions'     => Division::where('is_active', true)->orderBy('name')->get(),
            'brands'        => Brand::orderBy('name')->get(),
            'cities'        => \App\Models\City::orderBy('name')->get(),
            'institutions'  => \App\Models\Institution::where('is_active', true)->orderBy('name')->get(),
            'faculties'     => \App\Models\Faculty::orderBy('name')->get(),
            'studyPrograms' => \App\Models\StudyProgram::orderBy('name')->get(),
        ];

        return view('interns.index', array_merge($default, $data));
    }

    // Semua pemagang
    public function index()
    {
        return $this->renderIndex([
            'interns' => IR::orderByDesc('created_at')->get(),
            'title'   => 'Semua Pemagang',
            'scope'   => 'all',
        ]);
    }

    // Pemagang aktif
    public function active()
    {
        $interns = IR::where('internship_status', IR::STATUS_ACTIVE)
            ->orderByDesc('start_date')
            ->get();

        return $this->renderIndex([
            'interns' => $interns,
            'title'   => 'Pemagang Aktif',
            'scope'   => 'active',
        ]);
    }

    // Pemagang selesai
    public function completed()
    {
        $interns = IR::where('internship_status', IR::STATUS_COMPLETED)
            ->orderByDesc('end_date')
            ->get();

        return $this->renderIndex([
            'interns' => $interns,
            'title'   => 'Pemagang Selesai',
            'scope'   => 'completed',
        ]);
    }

    // Pemagang keluar
    public function exited()
    {
        $interns = IR::where('internship_status', IR::STATUS_EXITED)
            ->orderByDesc('updated_at')
            ->get();

        return $this->renderIndex([
            'interns' => $interns,
            'title'   => 'Pemagang Keluar',
            'scope'   => 'exited',
        ]);
    }

    // Pemagang pending
    public function pending()
    {
        $interns = IR::where('internship_status', IR::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();

        return $this->renderIndex([
            'interns' => $interns,
            'title'   => 'Pemagang Pending',
            'scope'   => 'pending',
        ]);
    }

    public function accepted()
    {
        $interns = IR::where('internship_status', IR::STATUS_ACCEPTED)
            ->orderByDesc('updated_at')
            ->get();

        return $this->renderIndex([
            'interns' => $interns,
            'title'   => 'Pemagang Diterima',
            'scope'   => 'accepted',
        ]);
    }

    public function rejected()
    {
        $interns = IR::where('internship_status', IR::STATUS_REJECTED)
            ->orderByDesc('updated_at')
            ->get();

        return $this->renderIndex([
            'interns' => $interns,
            'title'   => 'Pendaftar Ditolak',
            'scope'   => 'rejected',
        ]);
    }

    /**
     * Data Pendaftar Magang — status: waiting, accepted, rejected
     * Default filter: waiting (menunggu review)
     */
    public function pendaftar()
    {
        return $this->renderIndex([
            'title' => 'Data Pendaftar Magang',
            'scope' => request('scope', 'waiting'),           // default tab saat buka halaman
            'mode'  => 'pendaftar',         // dipakai view untuk tampilkan filter yang tepat
        ]);
    }

    /**
     * Data Pemagang — status: active, completed, exited
     * Default filter: active
     */
    public function pemagang()
    {
        return $this->renderIndex([
            'title' => 'Data Pemagang',
            'scope' => request('scope', 'active'),            // default tab saat buka halaman
            'mode'  => 'pemagang',          // dipakai view untuk tampilkan filter yang tepat
        ]);
    }

    /**
     * Arsip Pendaftaran Dihapus (Soft-Deleted)
     */
    public function trashed()
    {
        return $this->renderIndex([
            'interns' => IR::onlyTrashed()->orderByDesc('deleted_at')->get(),
            'title'   => 'Arsip Pendaftaran Dihapus',
            'scope'   => 'trashed',
            'mode'    => 'trashed',
        ]);
    }
}
