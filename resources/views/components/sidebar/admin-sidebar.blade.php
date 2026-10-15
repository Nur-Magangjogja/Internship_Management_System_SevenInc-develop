@php
    $icon = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'file' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg>',
        'clock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>',
        'x' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg>',
        'award' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="8" r="5"/><path d="m8.5 12.5-1 8 4.5-2.5 4.5 2.5-1-8"/></svg>',
        'video' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 2.12-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V20.3h-3v-.09a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.06.06-2.12-2.12.06-.06A1.7 1.7 0 0 0 7.08 15a1.7 1.7 0 0 0-1.55-1H5.4v-3h.13A1.7 1.7 0 0 0 7.08 10a1.7 1.7 0 0 0-.34-1.88l-.06-.06L8.8 5.94l.06.06A1.7 1.7 0 0 0 10.74 6.34a1.7 1.7 0 0 0 1-1.55V4.7h3v.09a1.7 1.7 0 0 0 1 1.55A1.7 1.7 0 0 0 17.62 6l.06-.06 2.12 2.12-.06.06A1.7 1.7 0 0 0 19.4 10a1.7 1.7 0 0 0 1.55 1h.13v3h-.13A1.7 1.7 0 0 0 19.4 15Z"/></svg>',
        'message' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.6 9.6 0 0 1-4-.9L3 21l1.8-4.4A8.2 8.2 0 0 1 3 11.5 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5Z"/></svg>',
        'building' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/></svg>',
        'phone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        'activity' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'table' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>',
        'archive' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>',
        'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 17l5-5-5-5M15 12H3M21 19V5a2 2 0 0 0-2-2h-6"/></svg>',
    ];

    $groups = [
        [
            'label' => 'Dashboard & Monitoring',
            'items' => [
                ['label' => 'Dashboard',         'route' => 'admin.dashboard.index',          'icon' => 'dashboard'],
                ['label' => 'Semua Pengguna',     'route' => 'admin.users.index',              'icon' => 'users'],
                ['label' => 'Kelola HR Staff',    'route' => 'admin.hr-staff.index',           'icon' => 'users'],
                ['label' => 'Master Institusi',   'route' => 'admin.institutions.index',       'icon' => 'building'],
                ['label' => 'Review Kontak Pembimbing', 'route' => 'admin.contact-review.index', 'icon' => 'check'],
                ['label' => 'Monitoring Follow-Up', 'route' => 'admin.follow-up.index',        'icon' => 'phone'],
                ['label' => 'Template MOU',        'route' => 'admin.mou-templates.index',      'icon' => 'file'],
                ['label' => 'Dokumen MOU & PKS',   'route' => 'admin.mous.index',               'icon' => 'file'],
                ['label' => 'Log Aktivitas',       'route' => 'admin.activity-logs.index',      'icon' => 'activity'],
                ['label' => 'Integrasi Spreadsheet', 'route' => 'admin.spreadsheet-connections.index', 'icon' => 'table'],
                ['label' => 'Monitoring Sinkronisasi', 'route' => 'admin.sync-monitoring.index', 'icon' => 'activity'],
                ['label' => 'Manajemen Brand',    'route' => 'admin.brands.index',             'icon' => 'settings'],
                ['label' => 'Manajemen Divisi',   'route' => 'admin.form-settings.divisions',  'icon' => 'settings'],
                ['label' => 'Pengaturan Form',     'route' => 'admin.form-settings.fields',     'icon' => 'settings'],
            ],
        ],
        [
            'label' => 'Pendaftaran & Status',
            'items' => [
                ['label' => 'Data Pendaftar Magang', 'route' => 'admin.interns.pendaftar', 'icon' => 'file'],
                ['label' => 'Data Pemagang',          'route' => 'admin.interns.pemagang',  'icon' => 'users'],
            ],
        ],
        [
            'label' => 'Dokumen & Sertifikat',
            'items' => [
                ['label' => 'Data LOA',             'route' => 'admin.documents.loas',        'icon' => 'file'],
                ['label' => 'Surat Penilaian',      'route' => 'interns.assessment.index',    'icon' => 'file'],
                ['label' => 'Data SKL',             'route' => 'admin.documents.skls',        'icon' => 'file'],
                ['label' => 'Sertifikat',          'route' => 'admin.certificate.index',     'icon' => 'award'],
                ['label' => 'Member Card',          'route' => 'admin.membercards.index',     'icon' => 'award'],
                ['label' => 'Informasi Alumni',     'route' => 'admin.intern_extras.index',   'icon' => 'users'],
            ],
        ],
        [
            'label' => 'Webinar',
            'items' => [
                ['label' => 'Kelola Webinar', 'route' => 'admin.webinars.index', 'icon' => 'video'],
            ],
        ],
    ];

    if (auth()->check() && auth()->user()->role === 'hr_staff') {
        $groups = [
            [
                'label' => 'Operasional HR',
                'items' => [
                    ['label' => 'Dashboard HR', 'route' => 'hr.dashboard', 'icon' => 'dashboard'],
                    ['label' => 'Data Institusi', 'route' => 'hr.institutions.index', 'icon' => 'building'],
                    ['label' => 'Review Kontak Pembimbing', 'route' => 'hr.contact-review.index', 'icon' => 'check'],
                    ['label' => 'Tugas Follow-Up', 'route' => 'hr.follow-up.index', 'icon' => 'phone'],
                    ['label' => 'Dokumen MOU', 'route' => 'hr.mous.index', 'icon' => 'file'],
                ],
            ],
        ];
    }
@endphp

<aside
    id="admin-sidebar"
    class="fixed inset-y-0 left-0 z-40 flex w-[272px] -translate-x-full flex-col border-r border-admin-border bg-white transition-all duration-300 lg:translate-x-0"
>
    <div class="flex h-16 items-center gap-3 border-b border-admin-border px-5">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[9px] bg-gradient-to-br from-admin-primary to-admin-primary-dark text-sm font-extrabold text-white">
            IH
        </div>

        <div class="admin-sidebar-label min-w-0">
            <p class="truncate text-[15px] font-bold text-admin-text-dark">InternHub</p>
            <p class="text-[11px] text-admin-text-mid">Panel Admin</p>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto px-3 py-4">
        @foreach ($groups as $groupIndex => $group)
            @php
                $isGroupActive = collect($group['items'])
                    ->contains(fn ($item) =>
                        match($item['route'] ?? '') {
                            'admin.interns.pendaftar' => request()->routeIs('admin.interns.pendaftar'),
                            'admin.interns.pemagang'  => request()->routeIs('admin.interns.pemagang'),
                            default => request()->routeIs($item['route'])
                        }
                    );
            @endphp

            <section class="admin-nav-group mb-2" data-nav-group>
                <button
                    type="button"
                    class="flex w-full items-center justify-between px-2.5 py-2 text-left"
                    data-nav-group-toggle
                    aria-expanded="{{ $isGroupActive ? 'true' : 'false' }}"
                >
                    <span class="admin-sidebar-label text-[11px] font-bold uppercase tracking-[0.06em] text-admin-text-mid">
                        {{ $group['label'] }}
                    </span>

                    <svg class="admin-sidebar-label h-4 w-4 text-admin-text-mid transition-transform {{ $isGroupActive ? '' : 'rotate-180' }}"
                         data-nav-chevron
                         viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </button>

                <div class="{{ $isGroupActive ? '' : 'hidden' }} space-y-1" data-nav-group-items>
                    @foreach ($group['items'] as $item)
                        @php
                            // Data Pendaftar: aktif di route pendaftar.* (waiting/accepted/rejected)
                            // Data Pemagang: aktif di route pemagang.* (active/completed/exited)
                            // Fallback: cek route persis
                            $isActive = match($item['route']) {
                                'admin.interns.pendaftar'   => request()->routeIs('admin.interns.pendaftar'),
                                'admin.interns.pemagang'    => request()->routeIs('admin.interns.pemagang'),
                                'admin.webinars.index'      => request()->routeIs('admin.webinars.*'),
                                'admin.mou-templates.index' => request()->routeIs('admin.mou-templates.*'),
                                'admin.mous.index'          => request()->routeIs('admin.mous.*'),
                                'admin.activity-logs.index' => request()->routeIs('admin.activity-logs.*'),
                                'admin.spreadsheet-connections.index' => request()->routeIs('admin.spreadsheet-connections.*'),
                                'admin.sync-monitoring.index' => request()->routeIs('admin.sync-monitoring.*'),
                                'admin.archive-exports.index' => request()->routeIs('admin.archive-exports.*'),
                                'admin.legacy-import.index' => request()->routeIs('admin.legacy-import.*'),
                                'admin.contact-review.index' => request()->routeIs('admin.contact-review.*'),
                                'hr.contact-review.index'    => request()->routeIs('hr.contact-review.*'),
                                'hr.mous.index'             => request()->routeIs('hr.mous.*'),
                                default => request()->routeIs($item['route']),
                            };
                        @endphp

                        <a
                            href="{{ route($item['route']) }}"
                            title="{{ $item['label'] }}"
                            class="{{ $isActive
                                ? 'bg-admin-primary text-white shadow-sm'
                                : 'text-admin-text-mid hover:bg-admin-secondary hover:text-admin-primary-dark' }}
                                flex items-center gap-3 rounded-lg px-3 py-2 text-[13.5px] font-medium transition"
                        >
                            <span class="h-[18px] w-[18px] shrink-0 [&>svg]:h-full [&>svg]:w-full [&>svg]:stroke-[2]">
                                {!! $icon[$item['icon']] !!}
                            </span>
                            <span class="admin-sidebar-label truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <div class="border-t border-admin-border p-3">
        <form action="{{ route('user.logout') }}" method="POST">
            @csrf
            <button
                type="submit"
                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-[13.5px] font-semibold text-admin-error transition hover:bg-red-50"
            >
                <svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M10 17l5-5-5-5M15 12H3M21 19V5a2 2 0 0 0-2-2h-6"/>
                </svg>
                <span class="admin-sidebar-label">Logout</span>
            </button>
        </form>
    </div>

    <button
        id="admin-sidebar-collapse"
        type="button"
        class="absolute -right-3 top-5 hidden h-6 w-6 items-center justify-center rounded-full border border-admin-border bg-white text-admin-text-mid shadow-sm lg:flex"
        aria-label="Ciutkan sidebar"
    >
        <svg class="h-3.5 w-3.5 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path d="m14 7-5 5 5 5"/>
        </svg>
    </button>
</aside>

<div id="admin-sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-admin-text-dark/40 lg:hidden"></div>