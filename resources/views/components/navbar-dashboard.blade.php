@php
    $user = auth()->user();
@endphp

<header id="admin-topbar" class="fixed inset-x-0 top-0 z-30 h-16 border-b border-admin-border bg-white transition-[left] duration-300 lg:left-[272px]">
    <div class="flex h-full items-center justify-between px-4 sm:px-6 lg:px-7">
        <div class="flex min-w-0 items-center gap-3">
            <button
                id="admin-sidebar-mobile-toggle"
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-admin-border text-admin-text-mid lg:hidden"
                aria-label="Buka menu"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M4 7h16M4 12h16M4 17h16"/>
                </svg>
            </button>

            <div class="min-w-0">
                <p class="truncate text-[17px] font-bold text-admin-text-dark">
                    @if($user && $user->role === 'hr_staff')
                        Panel HR Staff
                    @else
                        Panel Administrasi
                    @endif
                </p>
                <p class="hidden truncate text-xs text-admin-text-mid sm:block">InternHub</p>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="relative">
                <button
                    id="admin-notification-toggle"
                    type="button"
                    class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg border border-admin-border text-admin-text-mid transition hover:bg-admin-secondary"
                    aria-label="Notifikasi"
                >
                    <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>
                    </svg>
                    <span class="absolute right-2 top-2 h-1.5 w-1.5 rounded-full bg-admin-accent ring-2 ring-white"></span>
                </button>

                <div id="admin-notification-menu" class="absolute right-0 mt-2 hidden w-80 overflow-hidden rounded-xl border border-admin-border bg-white shadow-[0_12px_32px_rgba(27,58,52,0.14)]">
                    <div class="flex items-center justify-between border-b border-admin-border px-4 py-3">
                        <p class="text-sm font-bold text-admin-text-dark">Notifikasi</p>
                        <button type="button" class="text-xs font-semibold text-admin-primary">Tandai dibaca</button>
                    </div>

                    <div class="divide-y divide-admin-border">
                        <div class="px-4 py-3">
                            <p class="text-sm font-semibold text-admin-text-dark">Pendaftaran baru masuk</p>
                            <p class="mt-1 text-xs leading-5 text-admin-text-mid">Ada pendaftar baru yang menunggu review.</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-sm font-semibold text-admin-text-dark">Sistem berjalan normal</p>
                            <p class="mt-1 text-xs leading-5 text-admin-text-mid">Tidak ada pemberitahuan penting.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative">
                <button
                    id="admin-profile-toggle"
                    type="button"
                    class="flex items-center gap-2 rounded-full border border-admin-border py-1 pl-1 pr-2.5 transition hover:bg-admin-secondary"
                >
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-admin-secondary text-xs font-bold text-admin-primary-dark">
                        {{ strtoupper(substr($user->name ?? 'A', 0, 1)) }}
                    </span>

                    <span class="hidden text-left sm:block">
                        <span class="block max-w-28 truncate text-[13px] font-semibold text-admin-text-dark">{{ $user->name ?? 'Pengguna' }}</span>
                        <span class="block text-[11px] text-admin-text-mid">
                            {{ $user && $user->role === 'hr_staff' ? 'HR Staff' : 'Administrator' }}
                        </span>
                    </span>

                    <svg class="hidden h-4 w-4 text-admin-text-mid sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </button>

                <div id="admin-profile-menu" class="absolute right-0 mt-2 hidden w-56 overflow-hidden rounded-xl border border-admin-border bg-white py-2 shadow-[0_12px_32px_rgba(27,58,52,0.14)]">
                    <div class="border-b border-admin-border px-4 py-3">
                        <p class="truncate text-sm font-semibold text-admin-text-dark">{{ $user->name ?? 'Pengguna' }}</p>
                        <p class="truncate text-xs text-admin-text-mid">{{ $user->email ?? '' }}</p>
                    </div>

                    @if($user && $user->role === 'admin')
                        <a href="{{ route('admin.profile.edit') }}" class="block px-4 py-2.5 text-sm text-admin-text-mid hover:bg-admin-secondary hover:text-admin-primary-dark">
                            Edit Profil
                        </a>
                    @endif

                    <form action="{{ route('user.logout') }}" method="POST" class="border-t border-admin-border">
                        @csrf
                        <button type="submit" class="w-full px-4 py-2.5 text-left text-sm font-medium text-admin-error hover:bg-red-50">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>