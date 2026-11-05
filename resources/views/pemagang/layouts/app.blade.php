<!DOCTYPE html>
<html lang="id" class="overflow-x-hidden">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Dashboard') — InternHub</title>

  @vite(['resources/css/app.css','resources/js/app.js'])
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
  
  @stack('styles')

  <style>
    body { font-family: 'Inter', sans-serif; }

    /* Sidebar active state */
    .sidebar-link.active {
      background-color: rgba(255,255,255,0.15);
      font-weight: 600;
    }
    .sidebar-link:hover {
      background-color: rgba(255,255,255,0.10);
    }

    /* Sidebar custom subtle scrollbar */
    aside nav::-webkit-scrollbar {
      width: 4px;
    }
    aside nav::-webkit-scrollbar-thumb {
      background-color: rgba(255, 255, 255, 0.2);
      border-radius: 4px;
    }
  </style>
</head>

<body class="bg-gray-50 overflow-x-hidden">

  <div class="flex min-h-screen" x-data="{ mobileSidebarOpen: false }">

    {{-- Backdrop Overlay Mobile --}}
    <div x-show="mobileSidebarOpen"
         @click="mobileSidebarOpen = false"
         class="fixed inset-0 z-40 bg-black/50 lg:hidden"
         style="display: none;"
         x-transition.opacity>
    </div>

    {{-- ===== SIDEBAR ===== --}}
    <aside :class="mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-50 lg:z-30 w-64 lg:w-56 flex-shrink-0 flex flex-col transition-transform duration-300 ease-in-out shadow-xl lg:shadow-none"
           style="background-color: #1a5c38;">

      {{-- Brand & Mobile Close Button --}}
      <div class="flex items-center gap-3 px-4 py-5 border-b border-white/10 flex-shrink-0">
        <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center text-white font-bold text-sm">
          IH
        </div>
        <div class="text-white">
          <div class="text-sm font-semibold leading-tight">InternHub</div>
          <div class="text-xs text-white/60 leading-tight">Internship Platform</div>
        </div>
        <button @click="mobileSidebarOpen = false"
                type="button"
                class="lg:hidden ml-auto text-white/70 hover:text-white p-1 rounded-lg"
                aria-label="Tutup navigasi">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      {{-- Navigation --}}
      <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
        @php
          $reg = auth()->user()->internshipRegistration;
          $hasSubmitted = $reg && !$reg->is_draft;
        @endphp

        {{-- Dashboard --}}
        <a href="{{ route('pemagang.dashboard') }}"
           @click="mobileSidebarOpen = false"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/90 text-sm transition {{ request()->routeIs('pemagang.dashboard') ? 'active' : '' }}">
          <i class="fas fa-home w-4 text-center text-white/70"></i>
          Dashboard
        </a>

        {{-- Daftar Magang — selalu tampil --}}
        <a href="{{ route('pemagang.registration.form') }}"
           @click="mobileSidebarOpen = false"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/90 text-sm transition {{ request()->routeIs('pemagang.registration.*') ? 'active' : '' }}">
          <i class="fas fa-file-alt w-4 text-center text-white/70"></i>
          Daftar Magang
          @if(!$hasSubmitted)
            <span style="font-size:10px;background:#f59e0b;color:#fff;padding:1px 6px;border-radius:100px;margin-left:auto;">Wajib</span>
          @endif
        </a>

        {{-- Menu lain — hanya setelah submit form --}}
        @if($hasSubmitted)
        <a href="{{ route('pemagang.documents') }}"
           @click="mobileSidebarOpen = false"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/90 text-sm transition {{ request()->routeIs('pemagang.documents') ? 'active' : '' }}">
          <i class="fas fa-folder-open w-4 text-center text-white/70"></i>
          Dokumen Saya
          @if(($hasNewDocuments ?? false) && !request()->routeIs('pemagang.documents'))
            <span class="w-2.5 h-2.5 rounded-full bg-red-500 ring-2 ring-white/20 ml-auto shrink-0 animate-pulse" title="Ada file baru"></span>
          @endif
        </a>

        {{-- Webinar — hanya setelah submit form --}}
        <a href="{{ route('pemagang.webinar.index') }}"
           @click="mobileSidebarOpen = false"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/90 text-sm transition {{ request()->routeIs('pemagang.webinar.*') ? 'active' : '' }}">
          <i class="fas fa-video w-4 text-center text-white/70"></i>
          Webinar
          @php
            $pendingWebinarCount = $notificationWebinarCount ?? 0;
          @endphp
          @if($pendingWebinarCount > 0)
            <span style="font-size:10px;background:#ef4444;color:#fff;padding:1px 6px;border-radius:100px;margin-left:auto;">{{ $pendingWebinarCount }}</span>
          @endif
        </a>
        @else
        <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/30 text-sm cursor-not-allowed select-none">
          <i class="fas fa-folder-open w-4 text-center text-white/20"></i>
          Dokumen Saya
          <i class="fas fa-lock w-3 text-center text-white/20 ml-auto" style="font-size:10px;"></i>
        </div>
        <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/30 text-sm cursor-not-allowed select-none">
          <i class="fas fa-video w-4 text-center text-white/20"></i>
          Webinar
          <i class="fas fa-lock w-3 text-center text-white/20 ml-auto" style="font-size:10px;"></i>
        </div>
        @endif

        <a href="{{ route('pemagang.settings') }}"
           @click="mobileSidebarOpen = false"
           class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-white/90 text-sm transition {{ request()->routeIs('pemagang.settings') ? 'active' : '' }}">
          <i class="fas fa-cog w-4 text-center text-white/70"></i>
          Pengaturan
        </a>
      </nav>

      {{-- Logout --}}
      <div class="px-3 py-4 border-t border-white/10 flex-shrink-0">
        <form method="POST" action="{{ route('user.logout') }}">
          @csrf
          <button type="submit"
            class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-white/70 text-sm hover:text-white hover:bg-white/10 transition">
            <i class="fas fa-sign-out-alt w-4 text-center"></i>
            Logout
          </button>
        </form>
      </div>
    </aside>

    {{-- ===== MAIN AREA ===== --}}
    <div class="flex-1 flex flex-col min-w-0 min-h-screen lg:ml-56">

      {{-- ===== TOPBAR ===== --}}
      <header class="sticky top-0 z-20 h-14 bg-white/95 backdrop-blur-sm border-b border-gray-200 flex items-center justify-between px-4 sm:px-6 flex-shrink-0">
        {{-- Mobile Hamburger & Breadcrumb --}}
        <div class="flex items-center text-sm text-gray-500 min-w-0">
          <button @click="mobileSidebarOpen = !mobileSidebarOpen"
                  type="button"
                  class="lg:hidden mr-3 p-1.5 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:outline-none"
                  aria-label="Buka menu navigasi">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <span class="font-medium text-gray-700">InternHub</span>
          @hasSection('breadcrumb')
            <span class="mx-1">/</span>
            <span class="truncate">@yield('breadcrumb')</span>
          @endif
        </div>

        {{-- User info --}}
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-2">
            @php
              $authUser = auth()->user();
              $userPhoto = $authUser->internshipRegistration?->profile_photo ?? $authUser->profile_picture ?? $authUser->avatar ?? null;
              $userName = $authUser->internshipRegistration->fullname ?? $authUser->name ?? 'Pemagang';
            @endphp
            <x-image-display :src="$userPhoto" :alt="$userName" class="w-8 h-8 rounded-full object-cover shrink-0" :fallbackInitial="substr($userName, 0, 1)" type="avatar" />
            <div class="text-right hidden sm:block">
              <div class="text-sm font-medium text-gray-700 leading-tight">{{ auth()->user()->internshipRegistration->fullname ?? auth()->user()->name }}</div>
              <div class="text-xs text-gray-400 leading-tight">
                @php
                  $reg = auth()->user()->internshipRegistration;
                  $isAccepted = in_array($reg?->internship_status, ['accepted', 'active', 'completed']);
                @endphp
                @if($isAccepted && $reg?->internship_interest)
                  Pemagang · {{ $reg->internship_interest }}
                @elseif($isAccepted)
                  Pemagang
                @else
                  Calon Pemagang
                @endif
              </div>
            </div>
            {{-- Dropdown pengaturan --}}
            <div class="relative" x-data="{ open: false }">
              <button @click="open = !open" class="text-gray-400 hover:text-gray-600 ml-1">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
              <div x-show="open" @click.outside="open = false"
                   class="absolute right-0 mt-2 w-40 bg-white border border-gray-100 rounded-lg shadow-lg z-50 py-1"
                   style="top: 100%;">
                <a href="{{ route('pemagang.settings') }}"
                   class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                  <i class="fas fa-cog w-4 text-center text-gray-400"></i> Pengaturan
                </a>
                <hr class="my-1 border-gray-100">
                <form method="POST" action="{{ route('user.logout') }}">
                  @csrf
                  <button type="submit"
                    class="flex items-center gap-2 w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-4 text-center"></i> Logout
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </header>

      {{-- ===== CONTENT ===== --}}
      <main class="flex-1 p-3.5 sm:p-6 lg:p-8 min-w-0 max-w-full overflow-x-hidden">

        {{-- Flash messages --}}
        @if(session('success'))
          <div class="mb-4 flex items-center gap-2 bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg">
            <i class="fas fa-check-circle"></i>
            {{ session('success') }}
          </div>
        @endif

        @if(session('error'))
          <div class="mb-4 flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">
            <i class="fas fa-exclamation-circle"></i>
            {{ session('error') }}
          </div>
        @endif

        @yield('content')
      </main>

    </div>
  </div>

  @stack('scripts')
</body>
</html>
