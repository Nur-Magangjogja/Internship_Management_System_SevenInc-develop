# 🛡️ AI Development Rules & Guardrails: Internship Management System (SevenInc)

> **PENTING / PERHATIAN UTAMA (CRITICAL DIRECTIVE):**
> Seluruh tampilan antarmuka (UI/UX), layout, tata letak, palet warna, tipografi, dan komponen visual dalam proyek ini **SUDAH PATEN / FINAL**.
> **DILARANG KERAS** mengubah, merombak, mendesain ulang (redesign), atau merefaktor tampilan yang sudah ada tanpa izin eksplisit dari pengguna.

---

## 🎯 1. Cakupan & Fokus Tugas ke Depan
Setiap interaksi dan pengembangan oleh AI ke depannya berfokus pada:
1. **Peningkatan Logika Backend & Fitur**:
   - Controller, Model, Service, Repository, Request Validation, Policy/Middleware.
   - Database Migrations, Seeders, Factories, Eloquent Relationships.
   - Event, Listener, Queue Jobs, Scheduled Tasks.
2. **Integrasi & Sinkronisasi**:
   - Integrasi API & SSO (misal: sinkronisasi Web Intern ⟷ Web Absen Djuragan).
   - Google Spreadsheet sidecar & inbound/outbound sync.
   - Cloud Storage Registry & Document generation (PDF Browsershot/DomPDF, TipTap, MoU).
3. **Penyesuaian (Add & Blend-in)**:
   - Menambahkan input field, tombol aksi, kolom tabel, atau modal baru **HANYA** bila diperlukan oleh penambahan fitur baru, dengan **wajib mengikuti 100% gaya dan kelas visual yang sudah ada**.
4. **Bug Fixing & Optimasi**:
   - Memperbaiki bug fungsional, keamanan (security vulnerability), error handling, dan optimasi query database.

---

## 🚫 2. Larangan Keras (Strict Prohibitions)
- ❌ **DILARANG merombak Layout Utama**: Jangan mengubah struktur grid, flex container, sidebar, navbar, atau footer di:
  - `resources/views/layouts/dashboard.blade.php`
  - `resources/views/pemagang/layouts/app.blade.php`
  - `resources/views/components/sidebar/*`
  - `resources/views/components/navbar-dashboard.blade.php`
- ❌ **DILARANG mengubah Tema Warna & Design Tokens**:
  - Jangan mengganti skema warna `admin` (`admin-primary`, `admin-secondary`, `admin-bg`, dll) atau `primary` di `tailwind.config.js`.
  - Jangan menambahkan warna inline CSS baru atau styling acak yang merusak keharmonisan visual.
- ❌ **DILARANG mengubah Font & Typo System**:
  - Tetap gunakan font **Inter** dan icon **FontAwesome 6**.
- ❌ **DILARANG "Mempercantik" Tanpa Diminta (No Unsolicited Beautification)**:
  - Jangan mengganti elemen HTML/Tailwind yang sudah ada hanya karena preferensi estetika AI. Biarkan tampilan yang sudah ada bekerja apa adanya.
- ❌ **DILARANG merusak Script Interaktif**:
  - Jaga integritas Alpine.js (`x-data`, `x-show`, `x-cloak`), Three.js (3D Membercard), ApexCharts, TipTap, dan Flowbite.

---

## 🧩 3. Pedoman Menambahkan Elemen UI Baru (Seamless Integration)
Jika suatu penambahan fitur mengharuskan adanya elemen antarmuka baru:
1. **Gunakan Komponen yang Tersedia**:
   - Gunakan komponen Blade yang sudah ada di `resources/views/components/` (seperti `<x-form-field-group>`, pagination, dll).
2. **Replikasi Style Elemen Sejenis**:
   - **Tabel**: Tiru persis tag `<table>`, `<thead>`, `<th>`, `<tr>`, `<td>`, status badges, dan padding dari tabel yang sudah ada di halaman terkait.
   - **Tombol (Button)**: Gunakan class tombol standar proyek (misal: warna `bg-admin-primary hover:bg-admin-primary-dark text-white rounded-lg px-4 py-2 text-sm font-medium transition`).
   - **Form Input**: Gunakan border `border-admin-border focus:ring-admin-primary focus:border-admin-primary rounded-lg text-sm bg-white`.
   - **Modal**: Gunakan Flowbite modal atau Alpine.js modal dengan backdrop dan header/body/footer yang identik dengan modal yang sudah ada di modul tersebut.
3. **Uji Responsivitas**:
   - Pastikan penambahan baru tidak merusak tampilan mobile maupun desktop (`lg:ml-[272px]`, overlay sidebar, dll).

---

## ⚙️ 4. Standar Kode & Arsitektur
- **Framework**: Laravel 10 (PHP 8.1+), Tailwind CSS 3.4, Flowbite, Alpine.js, Vite.
- **Validasi**: Selalu gunakan Form Request atau validasi ketat sebelum memproses data.
- **Dokumentasi**: Pertahankan komentar kode, docstrings, dan dokumentasi API yang relevan.
- **Konservatif**: Saat mengedit file blade, lakukan patch minimal pada baris yang relevan; jangan menulis ulang seluruh file jika hanya menambahkan 1 field atau 1 tombol.
