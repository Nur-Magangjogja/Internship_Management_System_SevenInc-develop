# Internship Management System - SevenInc

Sistem Manajemen Magang (Internship Management System) untuk SevenInc, dibangun menggunakan **Laravel** dan **Tailwind CSS** (beserta **Flowbite**). Aplikasi ini bertujuan untuk memfasilitasi proses pendaftaran, pengelolaan data peserta magang, dan operasional lainnya terkait program magang di SevenInc.

---

## 🔗 Dokumentasi Integrasi API & SSO (Web Intern ⟷ Web Absen)
Panduan lengkap untuk menghubungkan, mengatur, dan mengganti endpoint API antara **Web Intern (`IMS`)** dan **Web Absen (`absen-djuragan`)**:

👉 **[Buka Dokumentasi Lengkap Integrasi API (API_DOCS_README.md)](./API_DOCS_README.md)**

Ringkasan cakupan:
- **Konfigurasi `.env`**: Penjelasan `ABSEN_DJURAGAN_API_URL`, `ABSEN_DJURAGAN_WEB_URL`, `ABSEN_DJURAGAN_API_KEY`.
- **Cara Mengganti Domain / Port / URL API**: Panduan migrasi Laragon, `artisan serve` port berbeda, hingga live server produksi.
- **Spesifikasi Endpoint**: Pengiriman data pendaftaran saat di-ACC (`register-sync`), verifikasi login admin (`verify-admin`), dan Single Sign-On (`SSO`).
- **Troubleshooting**: Solusi error 401, 404, 500, dan cara testing dengan cURL.

---

## 🚀 Fitur Utama
- **Pendaftaran Magang**: Calon peserta dapat mendaftar dan mengisi formulir magang.
- **Manajemen Peserta**: Admin dapat melihat, memvalidasi, dan mengelola data peserta magang.
- **Riwayat Unduhan/Sertifikat**: Modul untuk melacak riwayat unduhan dokumen atau sertifikat (termasuk status *Granted*).
- **Limitasi Pendaftar**: Pembatasan jumlah pendaftar magang secara dinamis.

## 💻 Tech Stack
- **Backend**: Laravel 10 / 11 (PHP 8.x)
- **Frontend**: Blade Templates, Tailwind CSS, Flowbite
- **Database**: MySQL
- **Assets Bundler**: Vite

## 🛠️ Persyaratan Sistem
Sebelum menjalankan aplikasi, pastikan sistem Anda telah terinstal:
- [PHP](https://www.php.net/) (minimal versi 8.1 atau sesuai kebutuhan versi Laravel yang digunakan)
- [Composer](https://getcomposer.org/)
- [Node.js & npm](https://nodejs.org/en/)
- [MySQL](https://www.mysql.com/) atau MariaDB

## ⚙️ Instalasi & Persiapan

Ikuti langkah-langkah di bawah ini untuk menjalankan proyek ini di *local environment* Anda:

1. **Clone repository ini**
   ```bash
   git clone https://github.com/username/Internship_Management_System_SevenInc.git
   cd Internship_Management_System_SevenInc
   ```

2. **Install dependensi PHP via Composer**
   ```bash
   composer install
   ```

3. **Install dependensi NPM**
   ```bash
   npm install
   ```

4. **Persiapkan file Environment**
   Duplikat file `.env.example` dan ubah namanya menjadi `.env`:
   ```bash
   cp .env.example .env
   ```
   Atur konfigurasi database di dalam file `.env`:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=nama_database_anda
   DB_USERNAME=root
   DB_PASSWORD=
   ```

5. **Generate Application Key**
   ```bash
   php artisan key:generate
   ```

6. **Migrasi Database**
   Jalankan migrasi untuk membuat tabel-tabel di database (tambahkan `--seed` jika Anda memiliki *seeder*):
   ```bash
   php artisan migrate
   ```

7. **Jalankan Aplikasi**
   Anda membutuhkan dua terminal untuk menjalankan *backend* dan *frontend assets* secara bersamaan:

   *Terminal 1 (Backend):*
   ```bash
   php artisan serve
   ```

   *Terminal 2 (Vite/Frontend):*
   ```bash
   npm run dev
   ```

   Aplikasi dapat diakses melalui browser pada `http://127.0.0.1:8000`.

## 🤝 Kontribusi
Silakan buat *pull request* untuk perbaikan *bug*, penambahan fitur, atau refaktorisasi kode. Pastikan untuk menguji perubahan Anda sebelum mengajukan *pull request*.

## 📄 Lisensi
Proyek ini bersifat *open-sourced* dan dilisensikan di bawah [MIT License](https://opensource.org/licenses/MIT).
