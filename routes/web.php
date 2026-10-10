<?php

use Illuminate\Support\Facades\Route;

// Public / Guest
use App\Http\Controllers\InternshipRegistrationController as PublicRegController;

// Auth & Admin
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InternController;
use App\Http\Controllers\Admin\InternPageController;
use App\Http\Controllers\Admin\InternApiController;
use App\Http\Controllers\Admin\CertificateGeneratorController;
use App\Http\Controllers\SKLController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\SuratPenilaianController;
// App
use App\Http\Controllers\UserController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\LoaController;
use App\Http\Controllers\MembercardController;
use App\Http\Controllers\InternAssessmentController;

// Pemagang area
use App\Http\Controllers\Pemagang\DashboardController as PemagangDashboard;
use App\Http\Controllers\Pemagang\RegistrationController as PemagangRegistration;
use App\Http\Controllers\Pemagang\DocumentController as PemagangDocument;
use App\Http\Controllers\Pemagang\SettingsController as PemagangSettings;


/*
|---------------------------------------------------------------------- 
| Web Routes 
|---------------------------------------------------------------------- 
| - Root -> login (auth/admin-login.blade.php) 
| - User login -> user.dashboard, Admin login -> admin/dashboard (atur di AuthController) 
| - Form internship dilindungi auth + submitted page 
| - Admin area: prefix URL 'admin' + prefix nama 'admin.' agar rapi & tidak bentrok
|---------------------------------------------------------------------- 
*/


// Demo bermacam macam tampilan
Route::view('/zombie-survival', 'cobacoba')->name('zombie.survival');

/* =================== ROOT -> LANDING PAGE =================== */
Route::get('/', function () {
    if (auth()->check()) {
        if (auth()->user()->role === 'admin') {
            return redirect()->route('admin.dashboard.index');
        }
        return redirect()->route('pemagang.dashboard');
    }
    return view('landing');
})->name('home');

/* =================== AUTH (GUEST) =================== */
// GET /login
Route::get('/login', function() {
    if (auth()->check()) {
        return auth()->user()->role === 'admin'
            ? redirect()->route('admin.dashboard.index')
            : redirect()->route('pemagang.dashboard');
    }
    return view('auth.login');
})->name('user.login')->middleware('guest');

Route::post('/login', [AuthController::class, 'login'])
    ->name('user.login.submit');

// SSO Login dari Absen Djuragan (Khusus Admin / Super Admin)
Route::get('/auth/sso', [AuthController::class, 'ssoLogin'])->name('auth.sso');

// GET /register
Route::get('/register', function() {
    return view('auth.register');
})->name('user.register')->middleware('guest');

Route::post('/register', [AuthController::class, 'register'])
    ->name('user.register.submit');

// Logout (user & admin)
Route::post('/logout', [AuthController::class, 'logout'])
    ->name('user.logout');

/* =================== USER ROUTES (Legacy — dipertahankan untuk kompatibilitas) =================== */
// Redirect user.dashboard ke pemagang.dashboard supaya link lama tidak broken
Route::get('/user/dashboard', fn() => redirect()->route('pemagang.dashboard'))
    ->name('user.dashboard')
    ->middleware('auth');

Route::middleware(['auth'])->group(function () {
    // Laporan harian, izin, tugas — tetap di sini karena dipakai UserController
    Route::get('/user/daily-report', [UserController::class, 'dailyReport'])->name('user.dailyReport');
    Route::post('/user/daily-report', [UserController::class, 'storeDailyReport'])->name('user.storeDailyReport');

    Route::get('/user/leave-request', [UserController::class, 'leaveRequest'])->name('user.leaveRequest');
    Route::post('/user/leave-request', [UserController::class, 'storeLeaveRequest'])->name('user.storeLeaveRequest');

    Route::get('/user/pending-tasks', [UserController::class, 'pendingTasks'])->name('user.pendingTasks');
    Route::post('/user/pending-tasks', [UserController::class, 'storePendingTask'])->name('user.storePendingTask');
});


Route::middleware(['auth'])->group(function () {
    Route::post('/user/loa/generate', [LoaController::class, 'generate'])
        ->name('user.loa.generate');
});


/* =================== INTERNSHIP (Legacy redirect) =================== */
// Redirect form lama ke form baru pemagang
Route::get('/internship', fn() => redirect()->route('pemagang.registration.form'))
    ->name('internship.form')
    ->middleware('auth');

// Submitted page masih dipakai InternshipRegistrationController lama
Route::view('/internship/submitted', 'pages.internship.submitted-page')
    ->name('internship.submitted')
    ->middleware('auth');

// Shortcut ke tabel internship (halaman admin)
Route::get('/internship/table', fn () => redirect()->route('admin.interns.index'))
    ->name('internship.table')
    ->middleware('auth');

// ========================
// Dokumen Kelulusan (SKL & LOA)
// ========================

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Halaman daftar SKL dan LOA
    Route::get('/documents/loas', [DocumentController::class, 'listLoas'])->name('documents.loas');
    Route::get('/documents/skls', [DocumentController::class, 'listSkls'])->name('documents.skls');

    // Serve file LOA/SKL dari storage (bypass symlink issue di XAMPP)
    Route::get('/documents/serve/{type}/{filename}', function (string $type, string $filename) {
        // Validasi type
        if (!in_array($type, ['loa', 'skl', 'tmp', 'rekomendasi'])) abort(404);

        // Sanitize filename — hanya huruf, angka, dash, underscore, titik
        if (!preg_match('/^[A-Za-z0-9_\-\.]+\.pdf$/', $filename)) abort(404);

        // Cari file: coba di public disk dulu, lalu di tmp
        $candidates = [
            storage_path("app/public/documents/{$type}/{$filename}"),
            storage_path("app/public/documents/skl/{$filename}"),
            storage_path("app/public/documents/loa/{$filename}"),
            storage_path("app/public/documents/rekomendasi/{$filename}"),
            storage_path("app/tmp/{$filename}"),
        ];

        $fullPath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $fullPath = $candidate;
                break;
            }
        }

        if (!$fullPath) {
            abort(404, 'File tidak ditemukan.');
        }

        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    })->name('documents.serve')->where('filename', '[A-Za-z0-9_\-\.]+');
});


Route::middleware(['auth', 'role:pemagang'])->prefix('user/documents')->name('user.')->group(function () {

    // Download dinamis (pemagang COMPLETED; admin boleh untuk user lain dengan ?user_id=)
    Route::get('/skl/download', [SKLController::class, 'download'])->name('skl.download');
    Route::get('/certificate/download', [CertificateController::class, 'downloadUserPdf'])->name('certificate.download');

    // Generate LOA (POST) — route ini sudah di-handle oleh user.loa.generate di atas
    // Hapus duplikasi ini agar tidak bentrok nama route

});

Route::middleware(['auth', 'role:admin'])->prefix('admin/documents')->name('admin.documents.')->group(function () {
    Route::get('/skl/{intern}', [\App\Http\Controllers\Admin\InternController::class, 'showSKL'])
        ->name('skl.show');
    Route::get('/loa/{intern}', [\App\Http\Controllers\Admin\InternController::class, 'showLOA'])
        ->name('loa.show');
});

Route::middleware(['auth','role:pemagang'])->group(function () {
    Route::get('/user/riwayat-magang', [\App\Http\Controllers\UserController::class, 'riwayatMagang'])
        ->name('user.riwayatMagang');
});

/* ===== Admin Login (untuk middleware RoleMiddleware) ===== */
Route::get('/admin/login', [AuthController::class, 'showLoginForm'])
    ->name('admin.login')
    ->middleware('guest');

/* =================== ADMIN ROUTES =================== */
// Semua nama route akan diawali 'admin.' (mis: 'admin.dashboard.index')
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin', 'prevent-back'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/', fn () => redirect()->route('admin.dashboard.index'))->name('home');

    // Manajemen Brand
    Route::resource('brands', \App\Http\Controllers\Admin\BrandController::class);

    // Pengaturan Form — Divisi & Field Builder
    Route::prefix('form-settings')->name('form-settings.')->group(function () {
        // Divisi
        Route::get('/divisions',                              [\App\Http\Controllers\Admin\DivisionController::class, 'index'])->name('divisions');
        Route::post('/divisions',                             [\App\Http\Controllers\Admin\DivisionController::class, 'store'])->name('divisions.store');
        Route::put('/divisions/{division}',                   [\App\Http\Controllers\Admin\DivisionController::class, 'update'])->name('divisions.update');
        Route::post('/divisions/{division}/toggle',           [\App\Http\Controllers\Admin\DivisionController::class, 'toggle'])->name('divisions.toggle');
        Route::post('/divisions/reorder',                     [\App\Http\Controllers\Admin\DivisionController::class, 'reorder'])->name('divisions.reorder');
        Route::delete('/divisions/{division}',                [\App\Http\Controllers\Admin\DivisionController::class, 'destroy'])->name('divisions.destroy');

        // Form Fields Builder
        Route::get('/fields',                                 [\App\Http\Controllers\Admin\FormFieldController::class, 'index'])->name('fields');
        Route::post('/fields',                                [\App\Http\Controllers\Admin\FormFieldController::class, 'store'])->name('fields.store');
        Route::get('/fields/preview-data',                    [\App\Http\Controllers\Admin\FormFieldController::class, 'previewData'])->name('fields.preview-data');
        Route::post('/fields/reorder',                        [\App\Http\Controllers\Admin\FormFieldController::class, 'reorder'])->name('fields.reorder');
        Route::get('/fields/{field}/data',                    [\App\Http\Controllers\Admin\FormFieldController::class, 'show'])->name('fields.show');
        Route::put('/fields/{field}',                         [\App\Http\Controllers\Admin\FormFieldController::class, 'update'])->name('fields.update');
        Route::post('/fields/{field}/toggle',                 [\App\Http\Controllers\Admin\FormFieldController::class, 'toggle'])->name('fields.toggle');
        Route::post('/fields/{field}/toggle-required',        [\App\Http\Controllers\Admin\FormFieldController::class, 'toggleRequired'])->name('fields.toggle-required');
        Route::delete('/fields/{field}',                      [\App\Http\Controllers\Admin\FormFieldController::class, 'destroy'])->name('fields.destroy');
    });

    // Profil admin yang sedang login
    Route::get('/profile',  [AdminUserController::class, 'editProfile'])->name('profile.edit');
    Route::put('/profile',  [AdminUserController::class, 'updateProfile'])->name('profile.update');

    // Users CRUD -> admin.users.*
    Route::resource('users', AdminUserController::class);
    // Ban / Unban user
    Route::post('users/{user}/ban',   [AdminUserController::class, 'ban'])->name('users.ban');
    Route::post('users/{user}/unban', [AdminUserController::class, 'unban'])->name('users.unban');

    // API: ambil pemagang selesai berdasarkan brand (HARUS sebelum resource agar tidak bentrok dengan {certificate})
    Route::get('/certificate/interns-by-brand', [CertificateController::class, 'getInternsByBrand'])->name('certificate.interns-by-brand');

    // API: ambil detail webinar + peserta approved (untuk AJAX di halaman create sertifikat webinar)
    Route::get('/certificate/webinar-participants', [CertificateController::class, 'getWebinarParticipants'])->name('certificate.webinar-participants');

    Route::resource('certificate', CertificateController::class);

    Route::get('/certificate/index', [CertificateController::class, 'index'])->name('certificate'); // Add route for viewing certificates list

    // Interns (pages + status + certificates) -> admin.interns.*
    Route::prefix('interns')->name('interns.')->group(function () {
        Route::get('/', [InternPageController::class, 'index'])->name('index');
        Route::get('/active', [InternPageController::class, 'active'])->name('active');
        Route::get('/completed', [InternPageController::class, 'completed'])->name('completed');
        Route::get('/exited', [InternPageController::class, 'exited'])->name('exited');
        Route::get('/pending', [InternPageController::class, 'pending'])->name('pending');
        Route::get('accepted', [InternPageController::class, 'accepted'])->name('accepted');
        Route::get('rejected', [InternPageController::class, 'rejected'])->name('rejected');

        // Dua halaman utama baru
        Route::get('/pendaftar', [InternPageController::class, 'pendaftar'])->name('pendaftar');
        Route::get('/pemagang',  [InternPageController::class, 'pemagang'])->name('pemagang');

        // Update status & data
        Route::patch('/{intern}/status', [InternController::class, 'updateStatus'])->name('status.update');
        Route::patch('/bulk/status', [InternController::class, 'bulkUpdateStatus'])->name('status.bulk');
        Route::patch('/{intern}', [InternController::class, 'update'])->name('update');

        // Sertifikat default
        Route::get('/{intern}/certificate', [InternController::class, 'certificate'])->name('certificate');
        Route::get('/{intern}/certificate.pdf', [InternController::class, 'certificatePdf'])->name('certificate.pdf');
        

        // Sertifikat AreaKerjaCom
        Route::get('/{intern}/certificate/areakerjacom/preview', [InternController::class, 'certificateAreaKerjaCom'])->name('certificate.areakerjacom.preview');
        Route::get('/{intern}/certificate/areakerjacom', [InternController::class, 'certificateAreaKerjaComPdf'])->name('certificate.areakerjacom');
        Route::get('/{intern}/certificate/areakerjacom.pdf', [InternController::class, 'certificateAreaKerjaComPdf'])->name('certificate.areakerjacom.pdf');

        // Template dinamis
        Route::get('/{intern}/certificate/{template}.pdf', [InternController::class, 'certificatePdfDynamic'])
            ->name('certificate.dynamic')
            ->whereIn('template', ['certmagangjogjacom', 'certareakerjacom', 'certtipisinicom']);

        // Hapus intern
        Route::delete('/{intern}', [InternController::class, 'destroy'])->name('destroy');
    });

    // API Select interns & JSON -> admin.interns.search, admin.interns.api
    Route::get('/interns/search', [InternApiController::class, 'search'])->name('interns.search');
    Route::get('/interns.json',   [InternApiController::class, 'index'])->name('interns.api');

    // Generate dokumen (LOA / SKL / Sertifikat / Penilaian) → reusable endpoint
    Route::post('/interns/generate-doc', [\App\Http\Controllers\Admin\GenerateDocController::class, 'generate'])
        ->name('interns.generate.doc');

    // Riwayat dokumen terkirim — dihapus, sudah masuk ke Data SKL & Data LOA masing-masing

    // Certificate generator lama (opsional)
    Route::get('/certificate/form',          [CertificateGeneratorController::class, 'showForm'])->name('certificate.form');
    Route::post('/certificate/preview',      [CertificateGeneratorController::class, 'generatePreview'])->name('certificate.generatePreview');
    Route::get('/certificate/download/{id}', [CertificateGeneratorController::class, 'generatePDF'])->name('certificate.generatePDF');
    Route::post('/download-pdf',             [CertificateGeneratorController::class, 'generatePDF'])->name('download.pdf');

    // Bulk certificate custom -> admin.certificate.bulk.*
    Route::prefix('certificate/bulk')->name('certificate.bulk.')->group(function () {
        Route::get('/interns',  [CertificateController::class, 'bulkCreateInterns'])->name('interns.create');
        Route::post('/interns', [CertificateController::class, 'bulkStoreInterns'])->name('interns.store');

        Route::get('/external',  [CertificateController::class, 'bulkCreateExternal'])->name('external.create');
        Route::post('/external', [CertificateController::class, 'bulkStoreExternal'])->name('external.store');
    });


    // Download PDF satu sertifikat -> admin.certificate.pdf
    Route::get('/certificate/{certificate}/pdf', [CertificateController::class, 'downloadPdf'])->name('certificate.pdf');



    // Resource Certificate → sudah dideklarasikan di atas (baris awal admin group)
    // Route::resource('certificate', CertificateController::class); // dihapus duplikasi

    // Upload assets (bg/logo/ttd) -> admin.uploads.*
    Route::post('/uploads/backgrounds', [CertificateController::class, 'uploadBackground'])->name('uploads.backgrounds.store');
    Route::post('/uploads/logos',       [CertificateController::class, 'uploadLogo'])->name('uploads.logos.store');
    Route::post('/uploads/signatures',  [CertificateController::class, 'uploadSignature'])->name('uploads.signatures.store');

    Route::get('/user/{user}/daily-reports', [DashboardController::class, 'showReports'])->name('user.dailyReports');
    Route::get('/user/{user}/leave-requests', [DashboardController::class, 'showLeaves'])->name('user.leaveRequests');
    Route::get('/user/{user}/pending-tasks', [DashboardController::class, 'showTasks'])->name('user.pendingTasks');

    Route::get('/skl/interns-by-brand', [SKLController::class, 'getInternsByBrand'])->name('skl.interns_by_brand');
    Route::post('/skl/generate-brand', [SKLController::class, 'generateForBrand'])->name('skl.generate_brand');
    // ===== WEBINAR (menggantikan Sertifikat Non-Magang) =====
    Route::prefix('webinars')->name('webinars.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\WebinarController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Admin\WebinarController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\WebinarController::class, 'store'])->name('store');
        Route::get('/{webinar}/edit', [\App\Http\Controllers\Admin\WebinarController::class, 'edit'])->name('edit');
        Route::put('/{webinar}', [\App\Http\Controllers\Admin\WebinarController::class, 'update'])->name('update');
        Route::delete('/{webinar}', [\App\Http\Controllers\Admin\WebinarController::class, 'destroy'])->name('destroy');

        // Review bukti kehadiran
        Route::get('/{webinar}/attendances', [\App\Http\Controllers\Admin\WebinarController::class, 'attendances'])->name('attendances');
        Route::post('/{webinar}/attendances/{attendance}/approve', [\App\Http\Controllers\Admin\WebinarController::class, 'approve'])->name('attendances.approve');
        Route::post('/{webinar}/attendances/{attendance}/reject', [\App\Http\Controllers\Admin\WebinarController::class, 'reject'])->name('attendances.reject');
        Route::post('/{webinar}/approve-all', [\App\Http\Controllers\Admin\WebinarController::class, 'approveAll'])->name('attendances.approve_all');

        // Generate sertifikat untuk semua peserta approved (tanpa re-generate yang sudah ada)
        Route::post('/{webinar}/generate-certs', [\App\Http\Controllers\Admin\WebinarController::class, 'generateCerts'])->name('generate_certs');
    });

    // Akses Eksklusif (Rekomendasi, Alumni, Info Kerja)
    Route::get('/intern-extras', [\App\Http\Controllers\Admin\InternExtraController::class, 'index'])->name('intern_extras.index');
    Route::get('/intern-extras/{intern}/edit', [\App\Http\Controllers\Admin\InternExtraController::class, 'edit'])->name('intern_extras.edit');
    Route::put('/intern-extras/{intern}', [\App\Http\Controllers\Admin\InternExtraController::class, 'update'])->name('intern_extras.update');
    Route::delete('/intern-extras/{intern}/rekomendasi', [\App\Http\Controllers\Admin\InternExtraController::class, 'destroyRekomendasi'])->name('intern_extras.rekomendasi.destroy');
    Route::post('/intern-extras/{intern}/rekomendasi/save-template', [\App\Http\Controllers\Admin\InternExtraController::class, 'saveTemplate'])->name('intern_extras.rekomendasi.save_template');
    Route::post('/intern-extras/{intern}/send-all', [\App\Http\Controllers\Admin\InternExtraController::class, 'sendAll'])->name('intern_extras.send_all');

    // Template & Generate Surat Rekomendasi
    Route::get('/rekomendasi/editor',              [\App\Http\Controllers\Admin\RekomendasiController::class, 'edit'])->name('rekomendasi.editor');
    Route::post('/rekomendasi/editor',             [\App\Http\Controllers\Admin\RekomendasiController::class, 'update'])->name('rekomendasi.update');
    Route::get('/rekomendasi/preview',             [\App\Http\Controllers\Admin\RekomendasiController::class, 'preview'])->name('rekomendasi.preview');
    Route::get('/rekomendasi/interns-by-brand',    [\App\Http\Controllers\Admin\RekomendasiController::class, 'getInternsByBrand'])->name('rekomendasi.interns_by_brand');
    Route::post('/rekomendasi/generate-brand',     [\App\Http\Controllers\Admin\RekomendasiController::class, 'generateBulk'])->name('rekomendasi.generate_brand');
    Route::post('/rekomendasi/generate/{intern}',  [\App\Http\Controllers\Admin\RekomendasiController::class, 'generate'])->name('rekomendasi.generate');

    // Preview untuk panel editor (dipanggil dari iframe)
    Route::get('/skl/preview', [SKLController::class, 'preview'])->name('skl.preview');

    // Download SKL atas nama pemagang (khusus admin)
    Route::get('/skl/download/{user}', [SKLController::class, 'download'])
        ->name('skl.download.for_user');

});





Route::get('/skl-preview', function () {
    return view('user.skl');
});

Route::middleware(['auth'])->group(function () {

    // CRUD sederhana data pemagang (opsional jika sudah ada halaman lain)
    Route::get('/admin/loa/interns', [LoaController::class, 'indexInterns'])->name('admin.loa.interns');
    Route::post('/admin/loa/generate', [LoaController::class, 'generate'])->name('admin.loa.generate'); // single
    Route::post('/admin/loa/generate-batch', [LoaController::class, 'generateBatch'])->name('admin.loa.generateBatch'); // multiple (legacy)

    // API: pemagang accepted belum ada LOA berdasarkan brand
    Route::get('/admin/loa/interns-by-brand', [LoaController::class, 'getInternsByBrand'])->name('admin.loa.interns_by_brand');

    // Generate LOA per-pemagang berdasarkan brand (1 PDF per orang, ZIP kalau banyak)
    Route::post('/admin/loa/generate-brand', [LoaController::class, 'generateForBrand'])->name('admin.loa.generate_brand');

    // Preview (tanpa simpan)
    Route::get('/user/loa/preview', [LoaController::class, 'preview'])->name('user.loa.preview');
});

Route::post('/membercard/download', [MembercardController::class, 'downloadMembercard'])->name('membercard.download');

Route::prefix('admin')->name('admin.')->group(function () {
    // index
    Route::get('membercards', [MembercardController::class, 'index'])
        ->name('membercards.index');

    // Bulk generate — harus sebelum {code} agar tidak konflik
    Route::post('membercards/generate-bulk', [MembercardController::class, 'generateBulk'])
        ->name('membercards.generate.bulk');

    // Generate satu pemagang
    Route::post('membercards/{code}/generate', [MembercardController::class, 'generateOne'])
        ->name('membercards.generate.one');

    // show by code (public admin view for a membercard)
    Route::get('membercards/{code}', [MembercardController::class, 'show'])
        ->name('membercards.show');

    // edit & update by code
    Route::get('membercards/{code}/edit', [MembercardController::class, 'edit'])
        ->name('membercards.edit');

    // prefer PUT/PATCH for update
    Route::put('membercards/{code}', [MembercardController::class, 'update'])
        ->name('membercards.update');

    // optional fallback if your forms send POST instead of PUT
    Route::post('membercards/{code}', [MembercardController::class, 'update'])
        ->name('membercards.update.post');

    // destroy by code
    Route::delete('membercards/{code}', [MembercardController::class, 'destroy'])
        ->name('membercards.destroy');
});

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('generate-pdf', [SuratPenilaianController::class, 'showForm'])->name('generateForm');
    Route::post('generate-pdf', [SuratPenilaianController::class, 'generatePdf'])->name('generatePdf');
});


Route::middleware(['auth', 'role:admin'])
    ->prefix('admin/interns')
    ->group(function () {

    // === CRUD Assessment ===
    Route::get('/assessment/list', [InternAssessmentController::class, 'index'])->name('interns.assessment.index');
    Route::get('/assessment/create', [InternAssessmentController::class, 'create'])->name('interns.assessment.create');

    // === Bulk store (banyak pemagang sekaligus) ===
    Route::post('/assessment/store-bulk', [InternAssessmentController::class, 'storeBulk'])->name('interns.assessment.store_bulk');

    // === API: pemagang selesai per brand ===
    Route::get('/assessment/interns-by-brand', [InternAssessmentController::class, 'getInternsByBrand'])->name('interns.assessment.interns_by_brand');

    // === AJAX: simpan setting penandatangan per brand ===
    Route::post('/assessment/save-signatory', [InternAssessmentController::class, 'saveSignatoryAjax'])->name('interns.assessment.save_signatory');

    // === PDF Routes ===
    Route::get('/assessment/{id}/pdf', [InternAssessmentController::class, 'downloadPDF'])->name('interns.assessment.pdf');
    Route::get('/assessment/{id}/preview', [InternAssessmentController::class, 'previewPDF'])->name('interns.assessment.preview');

    // === AJAX Route untuk Aspek Berdasarkan Divisi ===
    Route::get('/ajax/aspek', [InternAssessmentController::class, 'getAspekByDivision'])->name('ajax.aspek');


    Route::delete('/assessment/{id}', [InternAssessmentController::class, 'destroy'])->name('interns.assessment.destroy');

});


// tetap ada route log-download (tidak di dalam admin group, jika publik)
Route::post('/log-download', [MembercardController::class, 'logDownload'])->name('log.download');
Route::view('/loa-preview', 'user.loa');

/* =================== PEMAGANG ROUTES (UI Baru) =================== */
Route::middleware(['auth'])->prefix('pemagang')->name('pemagang.')->group(function () {

    // Dashboard
    Route::get('/dashboard', [PemagangDashboard::class, 'index'])->name('dashboard');

    // Form Pendaftaran
    Route::get('/daftar', [PemagangRegistration::class, 'showForm'])->name('registration.form');
    Route::post('/daftar', [PemagangRegistration::class, 'store'])->name('registration.store');
    Route::post('/daftar/draft', [PemagangRegistration::class, 'saveDraft'])->name('registration.draft');

    // Dokumen Saya
    Route::get('/dokumen', [PemagangDocument::class, 'index'])->name('documents');

    // Download Surat Penilaian (assessment milik pemagang yang login)
    Route::get('/dokumen/surat-penilaian', [PemagangDocument::class, 'downloadSuratPenilaian'])->name('documents.surat_penilaian');

    // Download Sertifikat
    Route::get('/dokumen/sertifikat', [PemagangDocument::class, 'downloadSertifikat'])->name('documents.sertifikat');

    // Download Surat Rekomendasi (hanya jika admin sudah memberikan)
    Route::get('/dokumen/rekomendasi', [PemagangDocument::class, 'downloadRekomendasi'])->name('documents.rekomendasi');

    // Download Sertifikat Webinar (berdasarkan certificate_id dari attendance)
    Route::get('/dokumen/sertifikat-webinar/{certificate}', [PemagangDocument::class, 'downloadSertifikatWebinar'])->name('documents.sertifikat_webinar');

    // Lihat Membercard
    Route::get('/membercard', [PemagangDocument::class, 'viewMembercard'])->name('membercard');
    Route::get('/membercard/download', [PemagangDocument::class, 'downloadMembercard'])->name('membercard.download');

    // Pengaturan Akun
    Route::get('/pengaturan', [PemagangSettings::class, 'index'])->name('settings');
    Route::put('/pengaturan', [PemagangSettings::class, 'update'])->name('settings.update');

    // Webinar
    Route::get('/webinar', [\App\Http\Controllers\Pemagang\WebinarController::class, 'index'])->name('webinar.index');
    Route::get('/webinar/{webinar}', [\App\Http\Controllers\Pemagang\WebinarController::class, 'show'])->name('webinar.show');
    Route::post('/webinar/{webinar}/upload-proof', [\App\Http\Controllers\Pemagang\WebinarController::class, 'uploadProof'])->name('webinar.upload_proof');
});