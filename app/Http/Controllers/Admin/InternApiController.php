<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternshipRegistration as IR;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InternApiController extends Controller
{
    /* =======================
     * Label maps (Bahasa ID)
     * ======================= */

    private array $statusBadge = [
        'waiting'   => ['Menunggu', 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-200'],
        'active'    => ['Aktif',    'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'],
        'completed' => ['Selesai',  'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200'],
        'exited'    => ['Keluar',   'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'],
        'pending'   => ['Pending',  'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
        'accepted'  => ['Diterima', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'],
        'rejected'  => ['Ditolak',  'bg-gray-200 text-gray-700 dark:bg-gray-800/60 dark:text-gray-200'],
    ];


    private array $mapLaptop = [
        'yes-laptop' => 'Ya, punya laptop',
        'no-laptop'  => 'Tidak punya laptop',
        'yes'        => 'Ya, punya laptop',
        'no'         => 'Tidak punya laptop',
        'y'          => 'Ya, punya laptop',
        'n'          => 'Tidak punya laptop',
        'true'       => 'Ya, punya laptop',
        'false'      => 'Tidak punya laptop',
        '1'          => 'Ya, punya laptop',
        '0'          => 'Tidak punya laptop',
        'ya'         => 'Ya, punya laptop',
        'tidak'      => 'Tidak punya laptop',
    ];

    private array $mapTools = [
        'tool-corel-photoshop' => 'Corel / Photoshop',
        'tool-adobe-video'     => 'Adobe Premiere / After Effects',
        'tool-camera'          => 'Kamera',
        'tool-drone'           => 'Drone',
        'tool-pen-tablet'      => 'Pen Tablet',
        'tool-tripod'          => 'Tripod',
    ];

    private array $mapGender = [
        'male' => 'Laki-laki', 'pria' => 'Laki-laki', 'laki-laki' => 'Laki-laki', 'm' => 'Laki-laki', 'lk' => 'Laki-laki',
        'female' => 'Perempuan', 'wanita' => 'Perempuan', 'perempuan' => 'Perempuan', 'f' => 'Perempuan', 'pr' => 'Perempuan',
    ];

    private array $mapCurrentStatus = [
        'Fresh Graduate' => 'Lulusan Baru',
        'Student'        => 'Mahasiswa/Pelajar',
        'Employee'       => 'Karyawan',
        'Unemployed'     => 'Tidak Bekerja',
    ];

    private array $mapArrangement = [ // TIPE MAGANG (cara kerja)
        'onsite' => 'Onsite',
        'hybrid' => 'Hibrida',
        'remote' => 'Remote',
    ];

    private array $mapType = [ // JENIS MAGANG (skema)
        'campus'          => 'Magang Kampus',
        'mandiri'         => 'Magang Mandiri',
        'pkl'             => 'PKL',
        'kampus-merdeka'  => 'Kampus Merdeka',
        'mbkm'            => 'Kampus Merdeka',
    ];

    private array $mapInterest = [
        // Slug/ID  → Label en/ID (ditampilkan yang kanan)
        'project-manager'                  => 'Project Manager',
        'manajer proyek'                   => 'Project Manager',

        'administration'                   => 'Administration',
        'administrasi'                     => 'Administration',

        'hr'                               => 'Human Resources (HR)',
        'sumber daya manusia (hr)'         => 'Human Resources (HR)',

        'uiux'                             => 'UI/UX',
        'ui/ux'                            => 'UI/UX',

        'programmer'                       => 'Programmer (Front End / Backend)',
        'programmer (front end / backend)' => 'Programmer (Front End / Backend)',

        'photographer'                     => 'Photographer',
        'fotografer'                       => 'Photographer',

        'videographer'                     => 'Videographer',
        'videografer'                      => 'Videographer',

        'graphic-designer'                 => 'Graphic Designer',
        'desainer grafis'                  => 'Graphic Designer',

        'social-media-specialist'          => 'Social Media Specialist',
        'spesialis media sosial'           => 'Social Media Specialist',

        'content-writer'                   => 'Content Writer',
        'penulis konten'                   => 'Content Writer',

        'content-planner'                  => 'Content Planner',
        'perencana konten'                 => 'Content Planner',

        'marketing-and-sales'              => 'Sales & Marketing',
        'penjualan & pemasaran'            => 'Sales & Marketing',
        'penjualan dan pemasaran'          => 'Sales & Marketing',

        'public-relation'                  => 'Public Relations (Marcomm)',
        'hubungan masyarakat (marcomm)'    => 'Public Relations (Marcomm)',

        'digital-marketing'                => 'Digital Marketing',
        'pemasaran digital'                => 'Digital Marketing',

        'tiktok-creator'                   => 'TikTok Creator',
        'kreator tiktok'                   => 'TikTok Creator',

        'welding'                          => 'Welding',
        'pengelasan'                       => 'Welding',

        'customer-service'                 => 'Customer Service',
        'layanan pelanggan'                => 'Customer Service',
    ];

    // Yes/No umum → Ya/Tidak (INFO KOST & SUDAH BERKELUARGA)
    private array $mapYesNo = [
        'yes' => 'Ya', 'y' => 'Ya', '1' => 'Ya', 'true' => 'Ya', 'ya' => 'Ya',
        'no'  => 'Tidak', 'n' => 'Tidak', '0' => 'Tidak', 'false' => 'Tidak', 'tidak' => 'Tidak',
    ];
    


    /** Humanize slug → “Corel Photoshop” */
    private function humanizeSlug(string $val): string
    {
        $s = str_replace('-', ' ', trim($val));
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    /** Ambil label dari map (case-insensitive), fallback humanize slug */
    private function labelize(array $map, ?string $val): string
    {
        if ($val === null || $val === '') return '-';
        $key = Str::lower(trim($val));
        foreach ($map as $k => $v) {
            if (Str::lower($k) === $key) return $v;
        }
        return $this->humanizeSlug($val);
    }

    /** Untuk list yang dipisah koma: mapping satu-satu lalu gabung */
    private function labelizeList(array $map, ?string $csv): string
    {
        if ($csv === null || trim($csv) === '') return '-';
        $parts = array_filter(array_map('trim', explode(',', $csv)), fn($x) => $x !== '');
        if (empty($parts)) return '-';
        return implode(', ', array_map(fn($p) => $this->labelize($map, $p), $parts));
    }

    /**
     * GET /admin/interns.json
     * JSON untuk tabel (global search semua kolom + advanced per kolom + pagination).
     */
    public function index(Request $req)
    {
        $scope   = $req->get('scope', 'all');
        $perPage = (int) $req->get('per_page', 1000);
        $perPage = $perPage > 0 ? min($perPage, 1000) : 1000;

        // Kolom yang ikut di-search (sinkron dengan kolom di Blade)
        $searchable = [
            'fullname','born_date','student_id','gender','phone_number',
            'internship_reason','internship_type','internship_arrangement',
            'current_status','internship_status',
            'english_book_ability','supervisor_contact','supervisor_name',
            'start_date','end_date',
            'current_activities','boarding_info',
            'social_media_instagram',
            'created_at',
        ];

        $q = IR::with(['skills', 'tools']);

        // Scope status / Trashed
        if ($scope === 'trashed' || $req->get('status') === 'trashed') {
            $q->onlyTrashed();
        } elseif ($scope !== 'all') {
            $q->where('internship_status', $scope);
        }

        // ========== GLOBAL SEARCH (semua kolom) ==========
        if ($req->filled('q')) {
            $raw    = trim($req->get('q'));
            $tokens = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $q->where(function ($outer) use ($tokens, $searchable) {
                foreach ($tokens as $token) {
                    $tokLower = Str::lower($token);
                    $genderCandidates = $this->normalizeGenderKeywords($tokLower);
                    $dateCandidates   = $this->parseDateCandidates($token);

                    $outer->where(function ($inner) use ($searchable, $token, $genderCandidates, $dateCandidates) {
                        foreach ($searchable as $col) {
                            if ($col === 'created_at') {
                                foreach ($dateCandidates as $d) {
                                    $inner->orWhereDate('created_at', '=', $d);
                                }
                                $inner->orWhere('created_at', 'like', "%{$token}%");
                                continue;
                            }
                            if ($col === 'gender' && !empty($genderCandidates)) {
                                foreach ($genderCandidates as $g) {
                                    $inner->orWhere('gender', 'like', "%{$g}%");
                                }
                                continue;
                            }
                            $inner->orWhere($col, 'like', "%{$token}%");
                        }

                        // Search in relations
                        $inner->orWhereHas('user', function ($uq) use ($token) {
                            $uq->where('email', 'like', "%{$token}%");
                        });
                        $inner->orWhereHas('institution', function ($iq) use ($token) {
                            $iq->where('name', 'like', "%{$token}%");
                        });
                        $inner->orWhereHas('studyProgramRel', function ($sq) use ($token) {
                            $sq->where('name', 'like', "%{$token}%");
                        });
                        $inner->orWhereHas('facultyRel', function ($fq) use ($token) {
                            $fq->where('name', 'like', "%{$token}%");
                        });
                        $inner->orWhereHas('city', function ($cq) use ($token) {
                            $cq->where('name', 'like', "%{$token}%");
                        });
                        $inner->orWhereHas('division', function ($dq) use ($token) {
                            $dq->where('name', 'like', "%{$token}%");
                        });
                        $inner->orWhereHas('skills', function ($skq) use ($token) {
                            $skq->where('skill_name', 'like', "%{$token}%");
                        });
                        $inner->orWhereHas('tools', function ($tq) use ($token) {
                            $tq->where('tool_name', 'like', "%{$token}%");
                        });
                    });
                }
            });
        }

        // ========== ADVANCED FILTERS PER KOLOM ==========
        foreach ($searchable as $col) {
            if (!$req->filled($col)) continue;
            $val = trim($req->get($col));

            if ($col === 'created_at') {
                $dateCandidates = $this->parseDateCandidates($val);
                if (!empty($dateCandidates)) {
                    $q->where(function ($qq) use ($dateCandidates, $val) {
                        foreach ($dateCandidates as $d) $qq->orWhereDate('created_at', '=', $d);
                        $qq->orWhere('created_at', 'like', "%{$val}%");
                    });
                } else {
                    $q->where('created_at', 'like', "%{$val}%");
                }
                continue;
            }

            $q->where($col, 'like', "%{$val}%");
        }

        // ========== DATE RANGE FILTERS (Y-m-d) ==========
        if ($req->filled('start_date_from')) {
            $from = $this->normalizeYmdInput($req->get('start_date_from'));
            if ($from) $q->where('start_date', '>=', $from);
            else $q->where('start_date', 'like', '%'.trim($req->get('start_date_from')).'%');
        }
        if ($req->filled('start_date_to')) {
            $to = $this->normalizeYmdInput($req->get('start_date_to'));
            if ($to) $q->where('start_date', '<=', $to);
            else $q->where('start_date', 'like', '%'.trim($req->get('start_date_to')).'%');
        }
        if ($req->filled('end_date_from')) {
            $from = $this->normalizeYmdInput($req->get('end_date_from'));
            if ($from) $q->where('end_date', '>=', $from);
            else $q->where('end_date', 'like', '%'.trim($req->get('end_date_from')).'%');
        }
        if ($req->filled('end_date_to')) {
            $to = $this->normalizeYmdInput($req->get('end_date_to'));
            if ($to) $q->where('end_date', '<=', $to);
            else $q->where('end_date', 'like', '%'.trim($req->get('end_date_to')).'%');
        }

        // Urut terbaru
        $q->orderByDesc('created_at');

        // Cek apakah pencarian aktif
        $isSearching = $req->filled('q');

        if ($isSearching) {
            // Tanpa pagination
            $collection = $q->get();
            $list = $collection->all(); // array of IR
        } else {
            // Dengan pagination
            $paginator = $q->paginate($perPage)->appends($req->query());
            $list = $paginator->items(); // array of IR
        }

        // Map data → rows
        $statusCompleted = defined(IR::class.'::STATUS_COMPLETED') ? IR::STATUS_COMPLETED : 'completed';

        $rows = array_map(function (IR $r) use ($statusCompleted) {
            $canCert = ($r->internship_status === $statusCompleted);

            return [
                'id'            => $r->id,
                'fullname'      => $r->fullname,
                'born_date'     => $this->formatIndoDateOut($r->born_date),
                'born_date_raw' => $r->born_date,
                'student_id'    => preg_replace('/^(NIM|NIS)\s*/i', '', (string) $r->student_id),
                'email'         => $r->email,
                'internship_status' => $r->internship_status,
                'gender'        => $this->labelize($this->mapGender, $r->gender),
                'phone_number'  => $r->phone_number,
                'institution_name' => $r->institution_name,
                'study_program' => $r->study_program,
                'faculty'       => $r->faculty,
                'current_city'  => $r->current_city,
                'internship_reason' => $r->internship_reason,
                'internship_type' => $this->labelize($this->mapType, $r->internship_type),
                'internship_arrangement' => $this->labelize($this->mapArrangement, $r->internship_arrangement),
                'current_status' => $this->labelize($this->mapCurrentStatus, $r->current_status),
                'english_book_ability' => $r->english_book_ability,
                'supervisor_contact' => $r->supervisor_contact,
                'supervisor_name' => $r->supervisor_name,
                'internship_interest' => $this->labelize($this->mapInterest, $r->internship_interest),
                'internship_interest_other' => $r->internship_interest_other,
                'design_software' => $r->skills->where('skill_category', 'design')->pluck('skill_name')->implode(', '),
                'video_software' => $r->skills->where('skill_category', 'video')->pluck('skill_name')->implode(', '),
                'programming_languages' => $r->skills->where('skill_category', 'programming')->pluck('skill_name')->implode(', '),
                'digital_marketing_type' => $r->skills->where('skill_category', 'digital_marketing')->pluck('skill_name')->implode(', '),
                'digital_marketing_type_other' => $r->digital_marketing_type_other,
                'laptop_equipment' => $this->labelize($this->mapLaptop, $r->laptop_equipment),
                'owned_tools' => $r->tools->pluck('tool_name')->implode(', '),
                'owned_tools_other' => $r->owned_tools_other,
                'start_date' => $this->formatIndoDateOut($r->start_date),
                'start_date_raw' => $r->start_date,
                'end_date' => $this->formatIndoDateOut($r->end_date),
                'end_date_raw' => $r->end_date,
                'internship_info_sources' => $r->internship_info_sources,
                'internship_info_other' => $r->internship_info_other,
                'current_activities' => $r->current_activities,
                'boarding_info' => $this->labelize($this->mapYesNo, $r->boarding_info),

                'social_media_instagram' => $r->social_media_instagram,
                'cv_ktp_portofolio_pdf' => $r->cv_ktp_portofolio_pdf ? asset('storage/'.$r->cv_ktp_portofolio_pdf) : null,
                'portofolio_visual' => $r->portofolio_visual ? asset('storage/'.$r->portofolio_visual) : null,
                'created_at' => optional($r->created_at)?->toIso8601String(),
                'user_id'    => $r->user_id,
                'brand'      => $r->brand,
                'certificate_url' => $canCert ? route('admin.interns.certificate', $r) : null,
                'certificate_pdf_url' => $canCert ? route('admin.interns.certificate.pdf', $r) : null,
                'status_update_url' => route('admin.interns.status.update', $r),
                'internship_status'        => $r->internship_status,
                'internship_status_label'  => $this->statusView((string)$r->internship_status)['label'],
                'internship_status_class'  => $this->statusView((string)$r->internship_status)['class'],
                'deleted_at'               => optional($r->deleted_at)?->toIso8601String(),
                'is_trashed'               => $r->trashed(),
            ];
        }, $list);
        

        // Meta & links respons
        if ($isSearching) {
            $total = count($list);
            $meta = [
                'current_page' => 1,
                'per_page'     => $total,
                'total'        => $total,
                'last_page'    => 1,
            ];
            $links = [
                'first' => null,
                'prev'  => null,
                'next'  => null,
                'last'  => null,
            ];
        } else {
            $meta = [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ];
            $links = [
                'first' => $paginator->url(1),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
                'last'  => $paginator->url($paginator->lastPage()),
            ];
        }

        // Opsi untuk select di FE (hindari hardcode di JS/Blade)
        $selectOptions = [
            'internship_status' => array_map(function ($k) {
                return ['value' => $k, 'label' => $this->statusView($k)['label']];
            }, array_keys($this->statusBadge)),

            'gender' => [
                ['value' => 'male',   'label' => $this->labelize($this->mapGender, 'male')],
                ['value' => 'female', 'label' => $this->labelize($this->mapGender, 'female')],
            ],

            'internship_type' => array_map(function ($k) {
                return ['value' => $k, 'label' => $this->labelize($this->mapType, $k)];
            }, array_keys($this->mapType)),

            'internship_arrangement' => array_map(function ($k) {
                return ['value' => $k, 'label' => $this->labelize($this->mapArrangement, $k)];
            }, array_keys($this->mapArrangement)),


        ];

        // Template sertifikat yang tersedia (sinkron dengan routes)
        $certificateTemplates = [
            ['value' => 'certmagangjogjacom', 'label' => 'Magangjogja.com'],
            ['value' => 'certareakerjacom',   'label' => 'AreaKerja.com'],
            // NOTE: perhatikan catatan slug di bawah
            ['value' => 'certtitipsinicom',    'label' => 'Titipsini.com'],
        ];


        // Ambil konfigurasi form fields untuk detail & edit modal (dinamis)
        $formFields = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('form_fields')) {
                $formFields = \App\Models\FormField::active()
                    ->orderBy('sort_order')
                    ->get(['id', 'field_key', 'label', 'field_type', 'group_name', 'options', 'column_span', 'is_required', 'is_system'])
                    ->toArray();
            }
        } catch (\Throwable $e) {
            // tabel belum ada, biarkan kosong
        }

        return response()->json([
            'data'            => $rows,
            'meta'            => $meta,
            'links'           => $links,
            'select_options'  => $selectOptions,
            'certificate_templates' => $certificateTemplates,
            'form_fields'     => $formFields,
        ]);

    }

    /**
 * GET /admin/interns/search
 * Hasil untuk Select2 (AJAX): { results: [{id, text, division}] }
 * - Pencarian by fullname/email/phone_number/student_id
 * - Default hanya status 'completed' (bisa override ?completed=0)
 * - 'division' diambil dari mapping internship_interest -> KODE DIV
 */

    private function statusView(string $value): array
    {
        $v = strtolower(trim($value));
        $pair = $this->statusBadge[$v] ?? [ucfirst($v), 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'];
        return ['label' => $pair[0], 'class' => $pair[1]];
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $onlyCompleted = $request->has('completed')
            ? filter_var($request->get('completed'), FILTER_VALIDATE_BOOLEAN)
            : true;

        $builder = IR::query();

        if ($onlyCompleted) {
            $statusCompleted = defined(IR::class.'::STATUS_COMPLETED') ? IR::STATUS_COMPLETED : 'completed';
            $builder->where('internship_status', $statusCompleted);
        }

        if ($q !== '') {
            $builder->where(function ($w) use ($q) {
                $w->where('fullname', 'like', "%{$q}%")
                ->orWhereHas('user', function ($uq) use ($q) {
                    $uq->where('email', 'like', "%{$q}%");
                })
                ->orWhere('phone_number', 'like', "%{$q}%")
                ->orWhere('student_id', 'like', "%{$q}%");
            });
        }

        // Map interest → kode divisi (sesuaikan jika perlu)
        $interestToDivision = [
            'administration'            => 'ADM',
            'administrasi'              => 'ADM',

            'uiux'                      => 'UIUX',
            'ui-ux'                     => 'UIUX',
            'ui/ux'                     => 'UIUX',

            'programmer'                => 'PROG',
            'programmer (front end / backend)' => 'PROG',

            'hr'                        => 'HR',
            'human resources (hr)'      => 'HR',

            'social-media-specialist'   => 'SMM',
            'spesialis media sosial'    => 'SMM',

            'photographer'              => 'PV',
            'videographer'              => 'PV',
            'fotografer'                => 'PV',
            'videografer'               => 'PV',

            'content-writer'            => 'CW',
            'penulis konten'            => 'CW',

            'marketing-and-sales'       => 'MS',
            'penjualan & pemasaran'     => 'MS',
            'penjualan dan pemasaran'   => 'MS',

            'graphic-designer'          => 'CD',
            'desainer grafis'           => 'CD',

            'digital-marketing'         => 'DM',
            'pemasaran digital'         => 'DM',

            'public-relation'           => 'PR',
            'public relations (marcomm)'=> 'PR',
            'hubungan masyarakat (marcomm)' => 'PR',

            'tiktok-creator'            => 'TC',
            'kreator tiktok'            => 'TC',

            'content-planner'           => 'CP',
            'perencana konten'          => 'CP',

            'project-manager'           => 'PM',
            'manajer proyek'            => 'PM',

            'welding'                   => 'LAS',
            'pengelasan'                => 'LAS',

            'animation'                 => 'ANIM',
            'animasi'                   => 'ANIM',
        ];

        $items = $builder->orderBy('fullname')->limit(20)->get();

        $results = $items->map(function (IR $r) use ($interestToDivision) {
            // Normalisasi interest ke key map
            $rawInterest = (string) ($r->internship_interest ?? '');
            $key = Str::of($rawInterest)->lower()->replace('/', '-')->toString();

            $division = $interestToDivision[$key] ?? null;

            $text = $r->fullname;
            if ($division) {
                $text .= " ({$division})";
            }

            return [
                'id'       => $r->id,
                'text'     => $text,
                'division' => $division, // bisa dipakai auto-set divisi di FE
            ];
        });

        return response()->json(['results' => $results]);
    }




    /**
     * Konversi input tanggal bebas menjadi kandidat 'Y-m-d'.
     * Dukung: YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY, YYYYMMDD.
     */
    private function parseDateCandidates(string $s): array
    {
        $s = trim($s);
        $cands = [];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $cands[] = $s;

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $s)) {
            [$d, $m, $y] = explode('-', $s);
            $cands[] = sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
            [$d, $m, $y] = explode('/', $s);
            $cands[] = sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
        }

        if (preg_match('/^\d{8}$/', $s)) {
            $y = substr($s, 0, 4);
            $m = substr($s, 4, 2);
            $d = substr($s, 6, 2);
            $cands[] = sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
        }

        return array_values(array_unique(array_filter($cands)));
    }

    /** Normalisasi INPUT user ke Y-m-d; kalau gagal, return '' (untuk range filter) */
    private function normalizeYmdInput(string $s): string
    {
        $c = $this->parseDateCandidates($s);
        return $c[0] ?? '';
    }

    /**
     * Format tanggal ke Indonesia (d F Y), contoh: "08 Juni 2025".
     * Jika tidak bisa di-parse, kembalikan string aslinya (apa adanya).
     */
    private function formatIndoDateOut($v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            $d = Carbon::parse($v);
            $bulan = [
                'Januari','Februari','Maret','April','Mei','Juni',
                'Juli','Agustus','September','Oktober','November','Desember'
            ];
            return $d->format('d') . ' ' . $bulan[$d->month - 1] . ' ' . $d->format('Y');
        } catch (\Throwable $e) {
            return is_string($v) ? trim($v) : null;
        }
    }

    /**
     * Normalisasi kata kunci gender → daftar kandidat nilai.
     */
    private function normalizeGenderKeywords(string $g): array
    {
        $out = [];
        if (preg_match('/\b(perempuan|wanita|cewek|female|f)\b/u', $g)) {
            $out = array_merge($out, ['perempuan','Perempuan','wanita','Wanita','female','Female','F','f']);
        }
        if (preg_match('/\b(laki|pria|lelaki|cowok|male|m)\b/u', $g)) {
            $out = array_merge($out, ['laki-laki','Laki-laki','pria','Pria','male','Male','M','m']);
        }
        return array_values(array_unique($out));
    }
}
