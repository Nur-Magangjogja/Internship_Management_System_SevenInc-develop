# 🚀 Panduan Integrasi Google Drive & Google Spreadsheet (SertIF IMS)

Dokumen ini menjelaskan arsitektur, langkah-langkah deployment, konfigurasi antarmuka admin, serta panduan verifikasi untuk integrasi **Google Drive** dan **Google Spreadsheet** pada sistem SertIF (Internship Management System).

---

## 📑 Daftar Isi
1. [Arsitektur & Konsep Integrasi](#-1-arsitektur--konsep-integrasi)
2. [Prasyarat](#-2-prasyarat)
3. [Langkah 1: Setup & Deploy Google Apps Script Gateway](#-langkah-1-setup--deploy-google-apps-script-gateway)
4. [Langkah 2: Konfigurasi Environment Laravel (.env)](#-langkah-2-konfigurasi-environment-laravel-env)
5. [Langkah 3: Integrasi & Otorisasi Google Drive](#-langkah-3-integrasi--otorisasi-google-drive)
6. [Langkah 4: Integrasi & Otorisasi Google Spreadsheet (Multi-Brand)](#-langkah-4-integrasi--otorisasi-google-spreadsheet-multi-brand)
7. [Langkah 5: Pengujian & Verifikasi Sistem](#-langkah-5-pengujian--verifikasi-sistem)
8. [Panduan Pemecahan Masalah (Troubleshooting)](#-panduan-pemecahan-masalah-troubleshooting)

---

## 🏛️ 1. Arsitektur & Konsep Integrasi

SertIF menggunakan pendekatan **Unified Google Apps Script (GAS) Gateway**:
* **Tanpa Google Cloud Console OAuth2 yang Rumit**: Anda tidak perlu membuat OAuth Client ID, OAuth Consent Screen, atau mengelola Refresh Token yang rawan kedaluwarsa.
* **Satu Gateway Terpadu**: File `resources/apps-script/SertifGoogleIntegration.js` bertindak sebagai API server di sisi Google yang mengeksekusi operasi `SpreadsheetApp` dan `DriveApp` di akun Google Anda.
* **Keamanan Tingkat Tinggi (HMAC-SHA256)**: Setiap request dari Laravel ke Google Apps Script ditandatangani menggunakan kunci rahasia HMAC (*shared secret*), *nonce*, dan *timestamp* untuk mencegah pemalsuan request (*replay attack*).
* **Multi-Brand Tenant Isolation**: Masing-masing brand (SEVEN INC, Ada Kamar, Magang Jogja, dsb.) dapat memiliki Google Spreadsheet dan folder Google Drive pendaftaran tersendiri secara independen.

```
+-------------------------------------------------------------+
|                     Laravel Backend                         |
|  (Jobs, Controllers, SystemStorageConfig, SyncEngine)       |
+-------------------------------------------------------------+
                               |
               HMAC-SHA256 Signed JSON Request
                               v
+-------------------------------------------------------------+
|             Google Apps Script Web App (/exec)              |
|        (resources/apps-script/SertifGoogleIntegration.js)   |
+-------------------------------------------------------------+
               |                               |
       DriveApp API                    SpreadsheetApp API
               v                               v
+-----------------------------+ +-----------------------------+
|        Google Drive         | |      Google Spreadsheet     |
| - REGISTRASI/{Brand}/...    | | - Sheet Pendaftaran Brand   |
| - SYSTEM-ASSETS             | | - Sheet Follow-Up Kampus    |
| - GENERATED-DOCUMENTS       | | - 38 Kolom Kanonikal        |
+-----------------------------+ +-----------------------------+
```

---

## 📋 2. Prasyarat

Sebelum memulai, siapkan hal-hal berikut:
1. **Akun Google** (Gmail pribadi atau Google Workspace) yang akan digunakan untuk menyimpan dokumen Drive dan Google Spreadsheet.
2. **1 Folder Induk di Google Drive**:
   - Buat folder baru di Google Drive Anda, misalnya beri nama `SertIF Storage`.
   - Buka folder tersebut dan salin Folder ID dari URL browser:
     ```
     https://drive.google.com/drive/folders/1a2B3c4D5e6F7g8H9i0J_kLmNoP
                                            ^^^^^^^^^^^^^^^^^^^^^^^^^^ (Ini Folder ID)
     ```
3. **Google Spreadsheet untuk Pendaftaran & Follow-Up**:
   - Buat Google Spreadsheet baru untuk Pendaftaran Brand (misalnya `Database Pendaftaran Magang 2026`).
   - Buat Google Spreadsheet untuk Follow-Up Kerjasama Kampus/Sekolah.
   - Salin URL atau Spreadsheet ID-nya.

---

## ⚡ Langkah 1: Setup & Deploy Google Apps Script Gateway

Source code Google Apps Script sudah tersedia di dalam repositori:  
📂 `resources/apps-script/SertifGoogleIntegration.js`

### 1. Buat Proyek Google Apps Script
1. Buka browser dan kunjungi: **[https://script.google.com](https://script.google.com)**
2. Pastikan Anda login dengan Akun Google yang memiliki folder Google Drive di atas.
3. Klik tombol **New Project** (atau buka proyek Apps Script existing jika sudah ada).
4. Beri nama proyek, misalnya: `SertIF Google Integration Gateway`.

### 2. Salin Kode Integrasi
1. Pada editor Google Apps Script, buka file `Code.gs`.
2. Hapus seluruh isi default `function myFunction() { ... }`.
3. Buka file `resources/apps-script/SertifGoogleIntegration.js` dari proyek Laravel ini, salin **seluruh isinya**, dan tempelkan ke `Code.gs`.
4. Simpan proyek dengan menekan tombol **Save** (`Ctrl + S`).

### 3. Konfigurasi Script Properties (Kunci Rahasia)
1. Pada menu sebelah kiri editor Apps Script, klik ikon gerigi **Project Settings** (⚙️).
2. Gulir ke bawah ke bagian **Script Properties**, klik **Add script property**.
3. Masukkan:
   - **Property**: `SHARED_SECRET` (atau `SHARED_API_KEY`)
   - **Value**: Masukkan string acak yang kuat (misal: `Sertif_Secret_Key_2026_Secure_Hash_SevenInc!#`).
4. Klik **Save script properties**.

> [!NOTE]
> ID Spreadsheet, Folder ID Google Drive, dan allowlist target **TIDAK PERLU** diisi manual pada Script Properties. Sistem Laravel akan mengotorisasinya secara dinamis melalui fitur *Activate Authorized Target* dari Admin Dashboard.

### 4. Deploy sebagai Web App
1. Di pojok kanan atas editor Apps Script, klik tombol biru **Deploy** -> **New deployment**.
2. Klik ikon gerigi di sebelah kiri *Select type*, lalu pilih **Web app**.
3. Isi parameter deployment:
   - **Description**: `SertIF Production Gateway V1`
   - **Execute as**: **Me (alamat-email-anda@gmail.com)** *(Wajib "Me" agar script beroperasi dengan kuota & izin akun pemilik folder)*
   - **Who has access**: **Anyone** *(Wajib "Anyone" agar server Laravel dapat mengirim request HMAC; keamanan diamankan oleh signature)*
4. Klik **Deploy**.
5. Google akan meminta izin akses (*Authorization Required*):
   - Klik **Authorize access**.
   - Pilih akun Google Anda.
   - Jika muncul peringatan *"Google hasn't verified this app"*, klik **Advanced** -> lalu klik **Go to SertIF Google Integration Gateway (unsafe)**.
   - Klik **Allow**.
6. Setelah selesai, Google akan menampilkan dialog deployment. Salin **Web app URL** yang berakhiran `/exec`:
   ```
   https://script.google.com/macros/s/AKfycbz.../exec
   ```

---

## ⚙️ Langkah 2: Konfigurasi Environment Laravel (.env)

Buka file `.env` di root project Laravel Anda (`d:\laragon\www\Internship_Management_System_SevenInc-develop\.env`).

Sesuaikan baris konfigurasi berikut:

```env
# ==============================================================================
# PENYIMPANAN CLOUD SISTEM SERTIF (GOOGLE DRIVE & APPS SCRIPT GATEWAY)
# ==============================================================================
GOOGLE_APPS_SCRIPT_ENABLED=true
GOOGLE_APPS_SCRIPT_ENDPOINT_URL=https://script.google.com/macros/s/AKfycbz.../exec
GOOGLE_APPS_SCRIPT_SHARED_SECRET=Sertif_Secret_Key_2026_Secure_Hash_SevenInc!#
GOOGLE_APPS_SCRIPT_TIMEOUT_SECONDS=120
GOOGLE_DRIVE_ROOT_FOLDER_ID=1a2B3c4D5e6F7g8H9i0J_kLmNoP
```

Setelah mengubah `.env`, bersihkan cache konfigurasi Laravel:
```bash
php artisan optimize:clear
```

---

## 📁 Langkah 3: Integrasi & Otorisasi Google Drive

Langkah ini mengonfigurasi penyimpanan cloud untuk seluruh berkas pendaftaran pemagang (CV, KTM/KTP, Portofolio), template dokumen, MoU, dan aset sistem.

1. Buka browser dan login ke **Admin Dashboard**:
   ```
   http://localhost:8000/admin/cloud-storage
   ```
   *(Atau navigasi melalui sidebar: **Pengaturan Sistem** -> **Penyimpanan Cloud**)*

2. Pada form **Folder Induk Tunggal Google Drive (Kanonikal)**:
   - Masukkan URL Folder Google Drive atau Folder ID yang sudah Anda siapkan di Prasyarat:
     Contoh: `https://drive.google.com/drive/folders/1a2B3c4D5e6F7g8H9i0J_kLmNoP`
   - Pastikan opsi **Aktifkan Sinkronisasi Cloud** tercentang.
   - Klik **Simpan Konfigurasi**.

3. **Uji Akses Read-Only**:
   - Pada toolbar atas, klik tombol hijau **"Uji Koneksi & Izin Folder"** (Read-Only).
   - Sistem akan memanggil aksi `drive_ping` ke Apps Script untuk memastikan folder dapat diakses dan izin tulis tersedia.
   - Pesan sukses akan muncul jika terhubung.

4. **Otorisasi Target Folder (Aktivasi Resmi)**:
   - Klik tombol indigo **"Aktifkan Folder Root"** (Otorisasi).
   - Konfirmasi dialog peringatan.
   - Sistem akan mendaftarkan Folder ID tersebut ke Script Properties Apps Script Gateway secara permanen. Status koneksi akan berubah menjadi **Terhubung & Terverifikasi**.

### 📂 Struktur Folder Otomatis di Google Drive
Sistem menerapkan mekanisme *lazy-create* otomatis. Di dalam folder induk Anda, struktur folder akan terbentuk rapi seperti berikut:
```text
[Folder Induk Google Drive]
├── REGISTRASI/
│   └── 2026/
│       ├── SEVEN INC/
│       │   └── Nabila_Maulana_Wibowo_REG12/
│       │       ├── CV_Nabila.pdf
│       │       ├── Scan_KTM.pdf
│       │       └── Portofolio.pdf
│       └── Ada Kamar/
│           └── Budi_Santoso_REG15/
├── SYSTEM-ASSETS/
│   ├── brand-logos/
│   └── certificate-backgrounds/
├── GENERATED-DOCUMENTS/
│   ├── mou/
│   └── certificates/
└── ARCHIVES/
```

---

## 📊 Langkah 4: Integrasi & Otorisasi Google Spreadsheet (Multi-Brand)

SertIF mendukung isolasi pendaftaran multi-brand, di mana tiap brand memiliki Spreadsheet masing-masing.

1. Buka browser dan login ke **Admin Dashboard**:
   ```
   http://localhost:8000/admin/spreadsheet-connections
   ```
   *(Atau navigasi melalui sidebar: **Integrasi Google Spreadsheet**)*

2. Anda akan melihat kartu koneksi untuk **11 Brand Resmi** (SEVEN INC, Ada Kamar, Magang Jogja, Republik Web, dsb.) serta koneksi **Follow-Up Kerjasama**.

### Mengatur Spreadsheet Brand:
1. Klik tombol **Edit Konfigurasi** pada brand yang ingin Anda hubungkan (misal: *Spreadsheet Pendaftaran (SEVEN INC)*).
2. Isi kolom konfigurasi:
   - **URL Dokumen Google Spreadsheet**:
     Masukkan URL spreadsheet pendaftaran brand tersebut.  
     Contoh: `https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit`  
     *(ID Spreadsheet akan diekstrak secara otomatis).*
   - **Apps Script Web App Endpoint URL**:  
     Bisa dikosongkan jika sudah diisi di `.env`, atau isi URL `/exec` khusus jika menggunakan gateway terpisah.
   - **URL / ID Folder Google Drive (Penyimpanan Berkas Upload Pemagang)**:  
     Masukkan URL/ID Folder Google Drive untuk berkas pemagang brand ini (bisa sama dengan Folder Induk sistem atau folder khusus per brand).
3. Klik **Simpan Konfigurasi**.

### Otorisasi & Verifikasi Spreadsheet:
1. Setelah disimpan, pada bagian bawah kartu **Pengujian & Otorisasi Target**:
   - Klik **Uji Koneksi (Read-Only)** untuk memverifikasi apakah Spreadsheet dapat dibaca.
   - Klik **Aktifkan Target (Otorisasi)** untuk mengotorisasi Spreadsheet ID tersebut ke Google Apps Script Gateway.
2. Klik tombol **Detail & Skema** (atau klik nama koneksi):
   - Sistem akan otomatis mendeteksi tab tahun aktif (misal tab `2026`).
   - Sistem memetakan **38 Kolom Kanonikal Pendaftaran 2026** (Nama Lengkap, NIM/NIS, Program Studi, Email, No. WA, Link Berkas Drive, dsb.).
   - Jika tab di Google Sheet masih kosong, Apps Script akan otomatis membuatkan header kolom yang sesuai saat baris pertama disinkronkan.

---

## 🧪 Langkah 5: Pengujian & Verifikasi Sistem

### A. Audit Menyeluruh via Terminal Artisan
Jalankan perintah verifikasi diagnostik terpadu 5-tahap:
```bash
php artisan storage:verify-apps-script
```
Perintah ini akan melakukan audit:
1. Konfigurasi provider, kredensial HMAC, dan Web App endpoint.
2. Integritas kode `resources/apps-script/SertifGoogleIntegration.js`.
3. Ping gateway dan izin akses remote ke Google Drive.
4. Uji baca & tulis remote (*read/write check*).
5. Audit sampel berkas pendaftaran yang tersimpan di database.

### B. Menjalankan Automated Test Suite
Pastikan seluruh unit & feature test koneksi spreadsheet multi-brand lulus:
```bash
# Uji koneksi spreadsheet 11 brand & isolasi pendaftar
php artisan test tests/Feature/DedicatedBrandSpreadsheetConnectionTest.php

# Uji transfer pemagang antar brand beserta pemindahan folder Google Drive
php artisan test tests/Feature/BrandTransferSpreadsheetAndDriveTest.php

# Uji indikator status keterhubungan Drive & Spreadsheet
php artisan test tests/Unit/SpreadsheetConnectionLinkIndicatorTest.php
```

### C. Pemrosesan Antrean Sinkronisasi (Worker / Daemon)
Sinkronisasi baris data pendaftaran dan unggah berkas ke Drive dijalankan secara asinkron melalui antrean (*queue*).

Untuk menjalankan antrean di lingkungan lokal:
```bash
php artisan queue:work --timeout=120
```

Untuk memproses outbox event spreadsheet secara manual:
```bash
php artisan spreadsheet:process-outbox
```

Untuk memulihkan antrean yang sempat tertunda/gagal:
```bash
php artisan spreadsheet:sync-recovery
```

---

## 🛠️ Panduan Pemecahan Masalah (Troubleshooting)

### 1. Error: `AUTH_FAILED` (HTTP 401 / 403)
* **Penyebab**: Nilai `GOOGLE_APPS_SCRIPT_SHARED_SECRET` di file `.env` tidak sama persis dengan properti `SHARED_SECRET` di **Project Settings -> Script Properties** Google Apps Script.
* **Solusi**:
  1. Buka kembali Google Apps Script -> Project Settings (⚙️) -> Script Properties.
  2. Pastikan nilai `SHARED_SECRET` sama persis dengan di `.env`.
  3. Lakukan deploy ulang dengan versi baru jika diperlukan.

### 2. Error: `TARGET_NOT_AUTHORIZED`
* **Penyebab**: ID Spreadsheet atau ID Folder Google Drive belum didaftarkan ke allowlist Apps Script.
* **Solusi**:
  1. Masuk ke halaman Edit Koneksi di Admin Panel (`/admin/spreadsheet-connections/{id}/edit`).
  2. Klik tombol **"Aktifkan Target (Otorisasi)"** atau tombol **"Aktifkan Folder Root"** di halaman Cloud Storage.

### 3. Peringatan: `paused_target_rebind`
* **Penyebab**: Terjadi perubahan ID Spreadsheet pada koneksi yang masih memiliki antrean baris pending. Demi keamanan data (*fail-closed*), antrean dibekukan sementara.
* **Solusi**:
  1. Buka detail koneksi (`/admin/spreadsheet-connections/{id}`).
  2. Klik notifikasi **Rekonsiliasi Antrean**.
  3. Pilih apakah ingin mengalihkan antrean ke target baru (*rebind_new_target*) atau membatalkan antrean lama.

### 4. File Upload Gagal Sinkron ke Google Drive
* **Penyebab**: Worker antrean belum berjalan, atau kuota Google Drive akun Anda penuh, atau batas ukuran file terlampaui.
* **Solusi**:
  1. Periksa tabel antrean di `/admin/cloud-storage` (kolom Status Antrean & Sinkronisasi).
  2. Pastikan worker antrean sedang aktif: `php artisan queue:work`.
  3. Periksa ukuran berkas (maksimal 10 MB per berkas pendaftaran).
  4. Jalankan `php artisan spreadsheet:sync-recovery` untuk memicu ulang unggahan yang pending/retrying.

---
*Dokumen ini diperbarui untuk SertIF IMS V1.3 — Multi-Brand Google Apps Script Gateway Architecture.*

