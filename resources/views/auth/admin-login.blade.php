<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login</title>
  @vite(['resources/css/app.css','resources/js/app.js'])

  {{-- Auto dark sesuai sistem (Tailwind darkMode: "class") --}}
  <script>
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      document.documentElement.classList.add('dark');
    }
  </script>
</head>
<body class="min-h-screen flex items-center justify-center bg-primary-500
             bg-gradient-to-br from-primary-400 via-primary-500 to-primary-700
             dark:from-primary-900 dark:via-primary-950 dark:to-black">

  <div class="w-full max-w-md px-4">
    <div class="rounded-2xl shadow-xl ring-1 ring-black/5 dark:ring-white/10
                bg-white/90 dark:bg-gray-900/85 backdrop-blur p-6 sm:p-8">

      <h2 class="mb-6 text-2xl font-bold text-center text-gray-900 dark:text-gray-100">
        Login
      </h2>

      @if(session('success'))
        <div class="mb-4 px-4 py-3 rounded-lg border
                    bg-green-50 text-green-700 border-green-200
                    dark:bg-green-950/40 dark:text-green-200 dark:border-green-900/50">
          {{ session('success') }}
        </div>
      @endif

      @if(session('error'))
        <div class="mb-4 px-4 py-3 rounded-lg border
                    bg-red-50 text-red-700 border-red-200
                    dark:bg-red-950/40 dark:text-red-200 dark:border-red-900/50">
          {{ session('error') }}
        </div>
      @endif

      <form action="{{ route('user.login.submit') }}" method="POST" class="space-y-4">
        @csrf

        <!-- Email atau Username -->
        <div>
          <label for="login" class="block text-sm font-medium text-gray-800 dark:text-gray-200">Email atau Username</label>
          <input type="text" id="login" name="login" required autocomplete="username"
                 value="{{ old('login', old('email')) }}"
                 placeholder="you@example.com atau username"
                 class="mt-1 w-full px-4 py-2 rounded-lg border
                        bg-white text-gray-900 placeholder-gray-400
                        border-gray-300 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500
                        dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-400 dark:border-gray-700 dark:focus:ring-primary-400 dark:focus:border-primary-400"/>
        </div>

        <!-- Password -->
        <div>
          <label for="password" class="block text-sm font-medium text-gray-800 dark:text-gray-200">Password</label>
          <div class="relative mt-1">
            <input type="password" id="password" name="password" required autocomplete="current-password"
                   placeholder="••••••••"
                   class="w-full px-4 pr-10 py-2 rounded-lg border
                          bg-white text-gray-900 placeholder-gray-400
                          border-gray-300 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500
                          dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-400 dark:border-gray-700 dark:focus:ring-primary-400 dark:focus:border-primary-400"/>
            <button type="button" id="togglePassword" aria-label="Lihat password"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 focus:outline-none transition">
              <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <!-- Remember Me -->
        <div class="flex items-center">
          <input type="checkbox" id="remember" name="remember"
                 class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500
                        dark:bg-gray-800 dark:border-gray-700 dark:focus:ring-primary-400"/>
          <label for="remember" class="ml-2 text-sm text-gray-700 dark:text-gray-300">Remember me</label>
        </div>

        <!-- Submit -->
        <button type="submit"
                class="w-full inline-flex justify-center items-center gap-2
                       bg-primary-600 hover:bg-primary-700 text-white font-medium
                       py-2.5 px-4 rounded-lg shadow-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500
                       dark:focus:ring-offset-gray-900">
          Login
        </button>

        <div class="mt-4 text-center">
          <a href="{{ route('user.register') }}" class="text-sm text-primary-600 hover:underline dark:text-primary-300">
            Don't have an account? Register here
          </a>
        </div>



      </form>

      <p class="mt-6 text-center text-xs text-primary-900/80 dark:text-primary-200/70">
        Secured area — authorized users only.
      </p>
    </div>
  </div>

  <script>
    // show/hide password (menggunakan SVG eye open / eye closed)
    const pw = document.getElementById('password');
    const pwBtn = document.getElementById('togglePassword');
    if (pw && pwBtn) {
      pwBtn.addEventListener('click', () => {
        const isHidden = pw.type === 'password';
        pw.type = isHidden ? 'text' : 'password';
        pwBtn.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Lihat password');
        const icon = pwBtn.querySelector('.eye-icon');
        if (icon) {
          if (isHidden) {
            icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
          } else {
            icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';
          }
        }
      });
    }
  </script>
</body>
</html>
