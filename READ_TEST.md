# 🛠️ Panduan Eksekusi Terminal & Seeding Developer (Testing & Demo)

Dokumen ini berisi kumpulan perintah bash/terminal praktis untuk kebutuhan pengujian, seeding data pemagang dummy, maupun verifikasi fungsionalitas sistem oleh developer.

---

## 📌 1. Navigasi Direktori Proyek

Jalankan perintah ini pertama kali di terminal Anda sebelum mengeksekusi perintah lainnya.

### 🐧 Git Bash / Linux / Mac:
```bash
cd /d/laragon/www/Internship_Management_System_SevenInc-develop
```

### 🪟 PowerShell / Windows Terminal:
```powershell
cd d:\laragon\www\Internship_Management_System_SevenInc-develop
```

---

## 👥 2. Seeding Data Pemagang Dummy (Untuk Testing Developer)

### A. Eksekusi Seluruh Dataset Demo Pemagang (Rekomendasi)
Menjalankan gabungan `DummyPemagangSeeder` dan `InternshipRegistrationSeeder` sekaligus:
```bash
php artisan db:seed --class=DemoPemagangDatasetSeeder
```

### B. Eksekusi Seeder Pemagang Secara Spesifik (Opsional)
Jika hanya ingin men-seed salah satu bagian data pemagang:
```bash
# Hanya data pendaftar & riwayat pendaftaran
php artisan db:seed --class=DummyPemagangSeeder
```
```bash
# Hanya data pendaftar yang sudah aktif / terstruktur
php artisan db:seed --class=InternshipRegistrationSeeder
```

---

## 🏢 3. Seeding Data Brand Resmi

### A. Baseline Brand Bersih (Default Sistem saat ini)
Men-seed 11 brand resmi dasar (hanya Nama & Kode, isian operasional Draft untuk diisi manual oleh Admin):
```bash
php artisan db:seed --class=BrandSeeder
```

### B. Full Dataset Brand Legacy (Opsional untuk Testing Domain & Divisi)
Men-seed 11 brand lengkap beserta domain, alamat lama, dan mapping divisi:
```bash
php artisan db:seed --class=OfficialBrandsDatasetSeeder
```

---

## 🔄 4. Reset & Migrasi Database Bersih

### A. Fresh Migration + Seeder Baseline Proyek
Membersihkan seluruh database dan mengisi data master serta 11 brand dasar (tanpa data pemagang dummy):
```bash
php artisan migrate:fresh --seed
```

### B. Fresh Migration + Full Data Uji Coba Developer (All-in-One)
Membersihkan database, mengisi master baseline, lalu menginjeksi dataset brand dan pemagang demo:
```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=OfficialBrandsDatasetSeeder
php artisan db:seed --class=DemoPemagangDatasetSeeder
```

---

## 🧪 5. Menjalankan Automated Test Suite

### A. Menjalankan Seluruh Pengujian Brand & Dokumen Multi-Tenant:
```bash
php artisan test tests/Feature/BrandSearchFilterTest.php tests/Feature/MultiBrandTenantIsolationFase1Test.php tests/Feature/BrandDivisionContractTest.php tests/Feature/CertificateBrandCodeGenerationTest.php tests/Feature/BrandLifecycleAndSpreadsheetSafetyTest.php tests/Feature/MultiBrandHostRegistrationTest.php tests/Feature/DedicatedBrandSpreadsheetConnectionTest.php tests/Feature/BrandDivisionRelationshipTest.php tests/Feature/AdminDashboardBrandDistributionTest.php tests/Feature/WebinarCertificateSourceTest.php tests/Feature/BrandDynamicActivationTest.php tests/Feature/EndToEndBrandTwelveSimulationTest.php tests/Feature/InternDocumentIssuanceTest.php
```

### B. Menjalankan Pengujian Spesifik per Fitur:
* **Pengujian Penerbitan Dokumen & Sertifikat:**
  ```bash
  php artisan test tests/Feature/InternDocumentIssuanceTest.php
  php artisan test tests/Feature/CertificateBrandCodeGenerationTest.php
  ```
* **Pengujian Kontrak MoU:**
  ```bash
  php artisan test tests/Feature/MouLifecycleTest.php
  ```
* **Pengujian Koneksi Spreadsheet Multi-Brand:**
  ```bash
  php artisan test tests/Feature/DedicatedBrandSpreadsheetConnectionTest.php
  ```
* **Pengujian Aktivasi & Verifikasi Host Brand:**
  ```bash
  php artisan test tests/Feature/BrandDynamicActivationTest.php
  ```

---

## 🧹 6. Pembersihan Cache & Refresh Konfigurasi

Jika Anda baru saja mengubah pengaturan brand, domain, atau konfigurasi env:
```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
```
*(Atau one-liner copy-paste)*:
```bash
php artisan optimize:clear
```

---

## 🌐 7. Panduan Testing Registrasi Multi-Brand via Domain Dummy (TrustHost & Localhost)

Panduan ini digunakan untuk menguji alur registrasi pemagang berbasis domain khusus brand (*Multi-Brand Tenant Isolation* via middleware `TrustHosts` dan `DetectBrandFromHost`) di lingkungan lokal (Laragon/Windows) tanpa harus membeli domain publik asli.

### Langkah 1: Input Konfigurasi Domain di Admin Panel
1. Buka Admin Dashboard -> Menu **Brand** -> Tambah atau Edit Brand.
2. Pada bagian **Konfigurasi Domain & Aktivasi**, masukkan:
   * **Host Pendaftaran:** `daftar.brandku.test`
   * **Domain Utama:** `brandku.test`
   *(Catatan: Host pendaftaran harus sama atau subdomain dari domain utama. Jangan gunakan IP address atau protokol http://).*
3. Simpan perubahan.

### Langkah 2: Simulasi Verifikasi DNS & Aktivasi Brand (Bypass DNS TXT Lokal)
Karena domain `.test` bersifat lokal dan tidak ada di server DNS publik internet, tombol "Verifikasi DNS TXT" di dashboard UI akan gagal. Jalankan simulasi aktivasi langsung via Laravel Tinker:

```bash
php artisan tinker
```
Eksekusi baris kode berikut:
```php
$brand = App\Models\Brand::where('registration_host', 'daftar.brandku.test')->first();
$brand->domain_verified_at = now();
$brand->domain_verification_method = 'manual_test';
$brand->lifecycle_status = 'active';
$brand->save();

// Bersihkan cache registry host tepercaya
Illuminate\Support\Facades\Cache::forget('brands.host_registry.v1');
exit
```

### Langkah 3: Tambahkan Domain Dummy ke File Hosts Windows (Terminal Administrator)
Agar sistem operasi Windows mengenali domain `daftar.brandku.test` dan mengarahkannya ke web server Laragon:

#### Pilihan A: Buka Notepad dengan Hak Administrator via Terminal (Rekomendasi)
1. Buka **Start Menu**, ketik `PowerShell` atau `cmd`.
2. Klik kanan lalu pilih **Run as administrator**.
3. Jalankan perintah:
   ```cmd
   notepad C:\Windows\System32\drivers\etc\hosts
   ```
4. Tambahkan baris berikut di paling bawah file:
   ```text
   127.0.0.1  daftar.brandku.test
   ```
5. Simpan file (**Ctrl + S**) dan tutup Notepad.

#### Pilihan B: Langsung Tambahkan via Baris Perintah PowerShell Administrator
Jalankan perintah ini di PowerShell Administrator:
```powershell
Add-Content -Path "$env:SystemRoot\System32\drivers\etc\hosts" -Value "`n127.0.0.1  daftar.brandku.test"
```
*(Opsional: Cek apakah baris sudah masuk dengan `Get-Content "$env:SystemRoot\System32\drivers\etc\hosts" -Tail 5`)*

#### Flush DNS Cache Windows:
Jalankan perintah berikut di terminal:
```cmd
ipconfig /flushdns
```

### Langkah 4: Pastikan Virtual Host Laragon (Nginx / Apache) Merespons
Pastikan domain dummy tersebut diarahkan ke folder `public` project Laragon Anda (`D:\laragon\www\Internship_Management_System_SevenInc-develop\public`).

#### Jika Laragon Menggunakan Nginx (Default di sistem Anda):
Buka file `D:\laragon\etc\nginx\sites-enabled\Internship_Management_System_SevenInc-develop.test.conf`.
Tambahkan nama domain dummy pada baris `server_name`:
```nginx
server {
    listen 80;
    server_name Internship_Management_System_SevenInc-develop.test *.Internship_Management_System_SevenInc-develop.test daftar.adakamar.test *.adakamar.test;
    root "D:/laragon/www/Internship_Management_System_SevenInc-develop/public";
    ...
```
Lalu reload Nginx (Klik kanan Laragon -> Nginx -> Reload, atau via terminal `d:\laragon\bin\nginx\nginx-1.28.2\nginx.exe -s reload -p d:\laragon\bin\nginx\nginx-1.28.2`).

#### Jika Menggunakan Apache:
Tambahkan ServerAlias pada konfigurasi Apache:
```apache
<VirtualHost *:80>
    DocumentRoot "D:/laragon/www/Internship_Management_System_SevenInc-develop/public"
    ServerName Internship_Management_System_SevenInc-develop.test
    ServerAlias daftar.adakamar.test *.adakamar.test
</VirtualHost>
```

### Langkah 5: Uji Registrasi di Browser
Buka browser dan akses salah satu URL berikut:
1. **Registrasi Akun Baru (Calon Pemagang):**
   ```
   http://daftar.adakamar.test/register
   ```
   *(Sistem akan otomatis mendeteksi brand "Ada Kamar" dari domain dan mengunci akun baru ke brand ini).*
2. **Landing Page Brand:**
   ```
   http://daftar.adakamar.test/
   ```
3. **Formulir Pengisian Biodata Magang (Setelah Login):**
   ```
   http://daftar.adakamar.test/pemagang/daftar
   ```

**Verifikasi:**
* Halaman terbuka normal (HTTP 200) dan **tidak ditolak** oleh middleware `TrustHosts`.
* Setelah registrasi akun baru di `/register`, periksa tabel `users` di database: field `brand_id` akan terisi ID brand "Ada Kamar" dan `registration_host` bernilai `daftar.adakamar.test`.

