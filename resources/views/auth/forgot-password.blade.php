<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Lupa Password — InternHub</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <style>
    * { font-family: 'Inter', sans-serif; }
    input:focus { outline: none; }
  </style>
</head>
<body style="background:#f8faf9; color:#1a1a1a; display:flex; justify-content:center; align-items:center; min-height:100vh;">

<div style="width:100%; max-width:440px; padding:20px;">
  <div style="text-align:center; margin-bottom:30px;">
    <a href="{{ route('home') }}" style="display:inline-flex; align-items:center; gap:10px; text-decoration:none;">
      <div style="width:32px; height:32px; border-radius:8px; background:#1a5c38; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:14px;">IH</div>
      <span style="font-weight:700; color:#1a1a1a; font-size:18px;">InternHub</span>
    </a>
  </div>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.06);">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#f8faf9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-key" style="color:#1a5c38;font-size:14px;"></i>
      </div>
      <div>
        <div style="font-weight:700;color:#111;font-size:15px;">Lupa Password</div>
        <div style="color:#9ca3af;font-size:12px;">Dapatkan tautan pemulihan kata sandi</div>
      </div>
    </div>

    @if(session('status'))
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:13px;padding:10px 14px;border-radius:8px;margin-bottom:16px;">
        {{ session('status') }}
      </div>
    @endif

    @if($errors->any())
      <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px;padding:10px 14px;border-radius:8px;margin-bottom:16px;">
        @foreach($errors->all() as $error)
          <div>{{ $error }}</div>
        @endforeach
      </div>
    @endif

    <p style="font-size:13px;color:#4b5563;line-height:1.5;margin-bottom:20px;">
      Masukkan alamat email akun Anda. Kami akan mengirimkan tautan untuk mengatur ulang password akun Anda.
    </p>

    <form method="POST" action="{{ route('password.email') }}">
      @csrf
      <div style="margin-bottom:20px;">
        <label for="email" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Alamat Email</label>
        <div style="position:relative;">
          <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                 placeholder="nama@email.com"
                 style="width:100%;padding:10px 14px 10px 38px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13px;box-sizing:border-box;"
                 onfocus="this.style.borderColor='#1a5c38'" onblur="this.style.borderColor='#e5e7eb'">
          <i class="fas fa-envelope" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
        </div>
      </div>

      <button type="submit"
              style="width:100%;padding:13px;background:#145c30;color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:.2s;"
              onmouseover="this.style.background='#1f2937'" onmouseout="this.style.background='#145c30'">
        <i class="fas fa-paper-plane" style="margin-right:8px;font-size:12px;"></i> Kirim Tautan Reset
      </button>
    </form>

    <div style="margin-top:20px;padding-top:20px;border-top:1px solid #f3f4f6;text-align:center;">
      <p style="font-size:12px;color:#9ca3af;">Ingat password akun Anda?
        <a href="{{ route('user.login') }}" style="color:#1a5c38;font-weight:600;text-decoration:none;">Kembali ke Login</a>
      </p>
    </div>
  </div>
</div>
</body>
</html>
