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
