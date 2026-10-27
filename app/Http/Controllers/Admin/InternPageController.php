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
        $yearsCreated = IR::selectRaw('YEAR(created_at) as y')->whereNotNull('created_at')->distinct()->pluck('y')->filter()->map(fn ($y) => (int) $y)->all();
        $yearsStart   = IR::selectRaw('YEAR(start_date) as y')->whereNotNull('start_date')->distinct()->pluck('y')->filter()->map(fn ($y) => (int) $y)->all();

        $yearBasis = request('year_basis', request('year_by', 'created_at'));
        if (!in_array($yearBasis, ['created_at', 'start_date'], true)) {
            $yearBasis = 'created_at';
        }

        $basisYears = $yearBasis === 'start_date' ? $yearsStart : $yearsCreated;
        $allYears   = array_values(array_unique(array_merge($basisYears, $yearsCreated, $yearsStart, [(int) date('Y')])));
        rsort($allYears);

        $formFields = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('form_fields')) {
                $targetYear = (request()->filled('year') && (int) request('year') >= 2020)
                    ? (int) request('year')
                    : (int) now()->year;
                if (!app()->runningUnitTests() && $targetYear <= (int) now()->year) {
                    app(\App\Services\FormBuilderService::class)->ensureYearFormInitialized($targetYear);
                }
                $activeSchema = \App\Models\FormSchema::where('year', $targetYear)->activePublished()->first()
                    ?: \App\Models\FormSchema::activePublished()->first()
                    ?: \App\Models\FormSchema::where('year', $targetYear)->orderByDesc('id')->first();

                if ($activeSchema) {
                    $formFields = $activeSchema->fields()
                        ->where('is_archived', false)
                        ->orderBy('sort_order')
                        ->get(['id', 'field_key', 'label', 'field_type', 'group_name', 'options', 'column_span', 'is_required', 'is_active', 'is_system', 'helper_text', 'placeholder'])
                        ->unique('field_key')
                        ->values()
                        ->toArray();
                } else {
                    $formFields = \App\Models\FormField::where('is_archived', false)
                        ->whereNull('form_schema_id')
                        ->orderBy('sort_order')
                        ->get(['id', 'field_key', 'label', 'field_type', 'group_name', 'options', 'column_span', 'is_required', 'is_active', 'is_system', 'helper_text', 'placeholder'])
                        ->unique('field_key')
                        ->values()
                        ->toArray();
                }
            }
        } catch (\Throwable $e) {
            // abaikan jika tabel belum siap
        }

        $default = [
            'years'         => $allYears,
            'yearsCreated'  => $yearsCreated,
            'yearsStart'    => $yearsStart,
            'yearBasis'     => $yearBasis,
            'divisions'     => Division::where('is_active', true)->orderBy('name')->get(),
            'brands'        => Brand::orderBy('name')->get(),
            'cities'        => \App\Models\City::orderBy('name')->get(),
            'institutions'  => \App\Models\Institution::where('is_active', true)->orderBy('name')->get(),
            'faculties'     => \App\Models\Faculty::orderBy('name')->get(),
            'studyPrograms' => \App\Models\StudyProgram::orderBy('name')->get(),
            'formFields'    => $formFields,
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
