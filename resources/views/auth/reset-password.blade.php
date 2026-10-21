<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Atur Ulang Password — InternHub</title>
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
        <i class="fas fa-lock" style="color:#1a5c38;font-size:14px;"></i>
      </div>
      <div>
        <div style="font-weight:700;color:#111;font-size:15px;">Atur Ulang Password</div>
        <div style="color:#9ca3af;font-size:12px;">Buat password baru akun Anda</div>
      </div>
    </div>

    @if($errors->any())
      <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px;padding:10px 14px;border-radius:8px;margin-bottom:16px;">
        @foreach($errors->all() as $error)
          <div>{{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">

      <div style="margin-bottom:16px;">
        <label for="email" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Alamat Email</label>
        <div style="position:relative;">
          <input type="email" id="email" name="email" value="{{ $email ?? old('email') }}" required readonly
                 style="width:100%;padding:10px 14px 10px 38px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13px;box-sizing:border-box;background:#f9fafb;color:#4b5563;">
          <i class="fas fa-envelope" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
        <div>
          <label for="password" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Password Baru <span style="color:#ef4444;">*</span></label>
          <div style="position:relative;">
            <input type="password" id="password" name="password" required placeholder="Min. 8"
                   style="width:100%;padding:10px 38px 10px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13px;box-sizing:border-box;"
                   onfocus="this.style.borderColor='#1a5c38'" onblur="this.style.borderColor='#e5e7eb'">
            <button type="button" class="toggle-reset-password-btn" onclick="toggleResetPasswords()" aria-label="Lihat password"
                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;cursor:pointer;color:#6b7280;display:flex;align-items:center;justify-content:center;">
              <svg class="eye-icon" style="width:18px;height:18px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <div>
          <label for="password_confirmation" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Konfirmasi <span style="color:#ef4444;">*</span></label>
          <div style="position:relative;">
            <input type="password" id="password_confirmation" name="password_confirmation" required placeholder="Ulangi"
                   style="width:100%;padding:10px 38px 10px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13px;box-sizing:border-box;"
                   onfocus="this.style.borderColor='#1a5c38'" onblur="this.style.borderColor='#e5e7eb'">
            <button type="button" class="toggle-reset-password-btn" onclick="toggleResetPasswords()" aria-label="Lihat password"
                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;cursor:pointer;color:#6b7280;display:flex;align-items:center;justify-content:center;">
              <svg class="eye-icon" style="width:18px;height:18px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>

      <button type="submit"
              style="width:100%;padding:13px;background:#145c30;color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:.2s;"
              onmouseover="this.style.background='#1f2937'" onmouseout="this.style.background='#145c30'">
        <i class="fas fa-save" style="margin-right:8px;font-size:12px;"></i> Simpan Password Baru
      </button>
    </form>

    <div style="margin-top:20px;padding-top:20px;border-top:1px solid #f3f4f6;text-align:center;">
      <p style="font-size:12px;color:#9ca3af;">Kembali ke halaman masuk?
        <a href="{{ route('user.login') }}" style="color:#1a5c38;font-weight:600;text-decoration:none;">Masuk di sini</a>
      </p>
    </div>
  </div>
</div>
<script>
function toggleResetPasswords() {
  const pw = document.getElementById('password');
  const pwConf = document.getElementById('password_confirmation');
  if (!pw || !pwConf) return;

  const isHidden = pw.type === 'password';
  const newType = isHidden ? 'text' : 'password';
  const newLabel = isHidden ? 'Sembunyikan password' : 'Lihat password';
  const newIcon = isHidden
    ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';

  pw.type = newType;
  pwConf.type = newType;

  document.querySelectorAll('.toggle-reset-password-btn').forEach(btn => {
    btn.setAttribute('aria-label', newLabel);
    const icon = btn.querySelector('.eye-icon');
    if (icon) {
      icon.innerHTML = newIcon;
    }
  });
}
</script>
</body>
</html>
