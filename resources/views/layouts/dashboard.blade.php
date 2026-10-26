<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Admin InternHub' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="{{ asset('vendor/alpine/alpine.min.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!window.Alpine) {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js';
                s.defer = true;
                document.head.appendChild(s);
            }
        });
    </script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>

<body class="min-h-screen bg-admin-bg font-['Inter'] text-admin-text-dark antialiased">
    <x-navbar-dashboard />
    <x-sidebar.admin-sidebar />

    <main
        id="admin-main-content"
        class="min-h-screen pt-16 transition-[margin] duration-300 lg:ml-[272px]"
    >
        @yield('content')
    </main>

    @stack('modals')
    @stack('scripts')

    @auth
    <script>
        (function() {
            const globalSyncTickUrl = @json(route('admin.spreadsheet-connections.global-sync-tick'));
            let isSyncTicking = false;

            async function runGlobalSyncTick() {
                if (isSyncTicking) return;
                isSyncTicking = true;
                try {
                    const res = await fetch(globalSyncTickUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    });
                    if (res.ok) {
                        const data = await res.json();
                        if (data && data.processed > 0 && window.dispatchEvent) {
                            window.dispatchEvent(new CustomEvent('spreadsheet-sync-updated', { detail: data }));
                        }
                    }
                } catch (e) {
                    // silent fail
                } finally {
                    isSyncTicking = false;
                }
            }

            // Jalankan global sync tick setiap 5 detik di seluruh halaman admin
            setInterval(runGlobalSyncTick, 5000);
        })();
    </script>
    @endauth
</body>
</html>