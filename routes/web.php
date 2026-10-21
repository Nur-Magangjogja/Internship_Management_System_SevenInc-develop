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
        if (auth()->user()->role === 'hr_staff') {
            return redirect()->route('hr.dashboard');
        }
        return redirect()->route('pemagang.dashboard');
    }
    return view('landing');
})->name('home');

/* =================== AUTH (GUEST) =================== */
// GET /login
Route::get('/login', function() {
    if (auth()->check()) {
        if (auth()->user()->role === 'admin') {
            return redirect()->route('admin.dashboard.index');
        }
        if (auth()->user()->role === 'hr_staff') {
            return redirect()->route('hr.dashboard');
        }
        return redirect()->route('pemagang.dashboard');
    }
    return view('auth.login');
})->name('user.login')->middleware('guest');

Route::post('/login', [AuthController::class, 'login'])
    ->name('user.login.submit')
    ->middleware('throttle:login');

// SSO Login dari Absen Djuragan (Khusus Admin / Super Admin)
Route::get('/auth/sso', [AuthController::class, 'ssoLogin'])->name('auth.sso');

// GET /register
Route::get('/register', function() {
    return view('auth.register');
})->name('user.register')->middleware('guest');

Route::post('/register', [AuthController::class, 'register'])
    ->name('user.register.submit')
    ->middleware(['guest', 'throttle:10,1']);

// Logout (user & admin)
Route::get('/logout', fn() => redirect()->route('user.login'));
Route::post('/logout', [AuthController::class, 'logout'])
    ->name('user.logout');

// Password Reset (Guest)
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [AuthController::class, 'showForgotPasswordForm'])
        ->name('password.request');

    Route::post('/forgot-password', [AuthController::class, 'sendResetLinkEmail'])
        ->name('password.email')
        ->middleware('throttle:6,1');

    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPasswordForm'])
        ->name('password.reset');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->name('password.update')
        ->middleware('throttle:6,1');

    // Account Activation (Guest)
    Route::get('/activate/{token}', [AuthController::class, 'showActivationForm'])
        ->name('user.activate');

    Route::post('/activate', [AuthController::class, 'activateAccount'])
        ->name('user.activate.submit')
        ->middleware('throttle:6,1');

    Route::post('/resend-activation', [AuthController::class, 'resendActivation'])
        ->name('user.activate.resend')
        ->middleware('throttle:6,1');
});

/* =================== USER ROUTES (Legacy — dipertahankan untuk kompatibilitas) =================== */
// Redirect user.dashboard ke pemagang.dashboard supaya link lama tidak broken
Route::get('/user/dashboard', fn() => redirect()->route('pemagang.dashboard'))
    ->name('user.dashboard')
    ->middleware('auth');

Route::middleware(['auth', 'role:pemagang'])->group(function () {
    // Laporan harian, izin, tugas — tetap di sini karena dipakai UserController
    Route::get('/user/daily-report', [UserController::class, 'dailyReport'])->name('user.dailyReport');
    Route::post('/user/daily-report', [UserController::class, 'storeDailyReport'])->name('user.storeDailyReport');

    Route::get('/user/leave-request', [UserController::class, 'leaveRequest'])->name('user.leaveRequest');
    Route::post('/user/leave-request', [UserController::class, 'storeLeaveRequest'])->name('user.storeLeaveRequest');

    Route::get('/user/pending-tasks', [UserController::class, 'pendingTasks'])->name('user.pendingTasks');
    Route::post('/user/pending-tasks', [UserController::class, 'storePendingTask'])->name('user.storePendingTask');

    Route::post('/user/loa/generate', [LoaController::class, 'generate'])->name('user.loa.generate');
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
    ->middleware(['auth', 'role:admin']);

// ========================
// Dokumen Kelulusan (SKL & LOA)
// ========================

Route::middleware(['auth'])->prefix('admin/generated-documents')->name('admin.generated-documents.')->group(function () {
    Route::get('/{uuid}/download', [\App\Http\Controllers\Admin\GeneratedDocumentController::class, 'download'])->name('download');
    Route::get('/{uuid}/preview', [\App\Http\Controllers\Admin\GeneratedDocumentController::class, 'preview'])->name('preview');
    Route::get('/{uuid}/status', [\App\Http\Controllers\Admin\GeneratedDocumentController::class, 'status'])->name('status');
    Route::post('/{uuid}/retry-sync', [\App\Http\Controllers\Admin\GeneratedDocumentController::class, 'retrySync'])
        ->middleware('role:admin,hr_staff')
        ->name('retry-sync');
});

Route::middleware(['auth', 'role:admin,hr_staff'])->prefix('admin')->name('admin.')->group(function () {
    // Halaman daftar SKL dan LOA
    Route::get('/documents/loas', [DocumentController::class, 'listLoas'])->name('documents.loas');
    Route::get('/documents/skls', [DocumentController::class, 'listSkls'])->name('documents.skls');

    // Serve file LOA/SKL dari storage (bypass symlink issue di XAMPP)
    Route::get('/documents/serve/{type}/{filename}', function (string $type, string $filename) {
        // Validasi type
        if (!in_array($type, ['loa', 'skl', 'tmp', 'rekomendasi', 'certificate', 'assessment', 'mou'])) abort(404);

        // Sanitize filename — hanya huruf, angka, dash, underscore, titik
        if (!preg_match('/^[A-Za-z0-9_\-\.]+\.pdf$/', $filename)) abort(404);

        // Cari via GeneratedDocument di database jika ada
        $genDoc = \App\Models\GeneratedDocument::where('file_name', $filename)->first();
        if ($genDoc && \Illuminate\Support\Facades\Storage::disk('local')->exists($genDoc->file_path)) {
            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($genDoc->file_path);
            return response()->file($fullPath, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]);
        }

        // Cari file: coba di private storage dulu, lalu tmp, lalu legacy public disk
        $candidates = [
            storage_path("app/documents/{$type}/{$filename}"),
            storage_path("app/documents/skl/{$filename}"),
            storage_path("app/documents/loa/{$filename}"),
            storage_path("app/documents/rekomendasi/{$filename}"),
            storage_path("app/private/documents/{$type}/{$filename}"),
            storage_path("app/tmp/{$filename}"),
            storage_path("app/public/documents/{$type}/{$filename}"),
            storage_path("app/public/documents/skl/{$filename}"),
            storage_path("app/public/documents/loa/{$filename}"),
            storage_path("app/public/documents/rekomendasi/{$filename}"),
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

// Serve berkas privat/foto dengan proteksi otorisasi kepemilikan (Modul 5)
Route::middleware(['auth'])->get('/media/private-serve', function (\Illuminate\Http\Request $request) {
    $encodedPath = $request->query('path');
    if (!$encodedPath) abort(404);

    $path = base64_decode($encodedPath, true);
    if (!$path || str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
        abort(403, 'Akses path tidak sah.');
    }

    $user = auth()->user();
    // Admin dan HR Staff berwenang melihat semua berkas operasional
    if (!in_array($user->role, ['admin', 'superadmin', 'hr_staff'], true)) {
        // Pemagang hanya boleh mengakses berkas miliknya sendiri
        $isOwner = \App\Models\RegistrationAttachment::where('file_path', $path)
            ->whereHas('registration', fn($q) => $q->where('user_id', $user->id))
            ->exists();

        if (!$isOwner) {
            $isProfileOwner = \App\Models\InternshipRegistration::where('user_id', $user->id)
                ->where('profile_photo', $path)
                ->exists();
            if (!$isProfileOwner && ($user->avatar ?? null) !== $path) {
                abort(403, 'Anda tidak memiliki hak akses untuk melihat berkas ini.');
            }
        }
    }

    if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
        abort(404, 'Berkas fisik tidak ditemukan.');
    }

    $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($path);
    $mime = mime_content_type($fullPath) ?: 'application/octet-stream';

    return response()->file($fullPath, [
        'Content-Type'  => $mime,
        'Cache-Control' => 'private, max-age=86400',
    ]);
})->name('media.private.serve');


Route::middleware(['auth', 'role:pemagang'])->prefix('user/documents')->name('user.')->group(function () {

    // Download dinamis (pemagang COMPLETED; admin boleh untuk user lain dengan ?user_id=)
    Route::get('/skl/download', [SKLController::class, 'download'])->name('skl.download');
    Route::get('/certificate/download', [CertificateController::class, 'downloadUserPdf'])->name('certificate.download');

    // Generate LOA (POST) — route ini sudah di-handle oleh user.loa.generate di atas
    // Hapus duplikasi ini agar tidak bentrok nama route

});

Route::middleware(['auth', 'role:admin,hr_staff'])->prefix('admin/documents')->name('admin.documents.')->group(function () {
    Route::get('/skl/{intern}', [\App\Http\Controllers\SKLController::class, 'review'])
        ->name('skl.show');
    Route::get('/loa/{intern}', [\App\Http\Controllers\LoaController::class, 'review'])
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

/* =================== SYNC MONITORING & RETRY ROUTES (Admin, Superadmin & HR Staff) =================== */
Route::prefix('admin/sync-monitoring')
    ->name('admin.sync-monitoring.')
    ->middleware(['auth', 'role:admin,superadmin,hr_staff', 'prevent-back'])
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SyncMonitoringController::class, 'index'])->name('index');
        Route::get('/{category}/{id}', [\App\Http\Controllers\Admin\SyncMonitoringController::class, 'show'])->name('show');
        Route::middleware('throttle:60,1')->group(function () {
            Route::post('/retry/main-row/{rowSync}', [\App\Http\Controllers\Admin\SyncMonitoringController::class, 'retryMainRow'])->name('retry-main-row');
            Route::post('/retry/sidecar/{sidecarSync}', [\App\Http\Controllers\Admin\SyncMonitoringController::class, 'retrySidecar'])->name('retry-sidecar');
            Route::post('/retry/attachment/{attachment}', [\App\Http\Controllers\Admin\SyncMonitoringController::class, 'retryAttachment'])->name('retry-attachment');
            Route::post('/batch-retry', [\App\Http\Controllers\Admin\SyncMonitoringController::class, 'batchRetry'])->name('batch-retry');
        });
    });

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
        Route::post('/fields/publish',                        [\App\Http\Controllers\Admin\FormFieldController::class, 'publish'])->name('fields.publish');
        Route::post('/fields/sync-previous',                  [\App\Http\Controllers\Admin\FormFieldController::class, 'syncFromPreviousYear'])->name('fields.sync-previous');
        Route::post('/fields/{field}/archive',                [\App\Http\Controllers\Admin\FormFieldController::class, 'archive'])->name('fields.archive');
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
    Route::post('users/{id}/restore', [AdminUserController::class, 'restore'])->name('users.restore');
    // Ban / Unban user
    Route::post('users/{user}/ban',   [AdminUserController::class, 'ban'])->name('users.ban');
    Route::post('users/{user}/unban', [AdminUserController::class, 'unban'])->name('users.unban');

    // HR Staff Management -> admin.hr-staff.*
    Route::resource('hr-staff', \App\Http\Controllers\Admin\HrStaffController::class);
    Route::post('hr-staff/{user}/toggle-status', [\App\Http\Controllers\Admin\HrStaffController::class, 'toggleStatus'])->name('hr-staff.toggle-status');
    Route::post('hr-staff/{id}/restore', [\App\Http\Controllers\Admin\HrStaffController::class, 'restore'])->name('hr-staff.restore');

    // Master Institusi -> admin.institutions.*
    Route::resource('institutions', \App\Http\Controllers\Admin\InstitutionController::class)->except(['destroy']);
    Route::post('institutions/{institution}/toggle-status', [\App\Http\Controllers\Admin\InstitutionController::class, 'toggleStatus'])->name('institutions.toggle-status');
    Route::post('institutions/{institution}/contacts', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'store'])->name('institutions.contacts.store');
    Route::put('institution-contacts/{contact}', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'update'])->name('institution-contacts.update');
    Route::post('institution-contacts/{contact}/toggle-status', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'toggleStatus'])->name('institution-contacts.toggle-status');
    Route::post('institution-contacts/{contact}/verify', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'verify'])->name('institution-contacts.verify');
    Route::delete('institution-contacts/{contact}', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'destroy'])
        ->middleware('role:admin')
        ->name('institution-contacts.destroy');

    // Review Kontak Pembimbing -> admin.contact-review.*
    Route::get('/contact-review', [\App\Http\Controllers\Admin\ContactReviewController::class, 'index'])->name('contact-review.index');
    Route::post('/contact-review/{registration}/link', [\App\Http\Controllers\Admin\ContactReviewController::class, 'linkExisting'])->name('contact-review.link');
    Route::post('/contact-review/{registration}/create-and-verify', [\App\Http\Controllers\Admin\ContactReviewController::class, 'createAndVerify'])->name('contact-review.create-and-verify');

    // Modul Follow-up Kemitraan -> admin.follow-up.*
    Route::get('/follow-up', [\App\Http\Controllers\HR\FollowUpController::class, 'index'])->name('follow-up.index');
    Route::get('/follow-up/create', [\App\Http\Controllers\HR\FollowUpController::class, 'create'])->name('follow-up.create');
    Route::post('/follow-up', [\App\Http\Controllers\HR\FollowUpController::class, 'store'])->name('follow-up.store');
    Route::get('/follow-up/{cycle}', [\App\Http\Controllers\HR\FollowUpController::class, 'show'])->name('follow-up.show');
    Route::post('/follow-up/{cycle}/attempts', [\App\Http\Controllers\HR\FollowUpController::class, 'recordAttempt'])->name('follow-up.attempts.store');
    Route::post('/follow-up/{cycle}/complete', [\App\Http\Controllers\HR\FollowUpController::class, 'complete'])->name('follow-up.complete');
    Route::post('/follow-up/{cycle}/claim', [\App\Http\Controllers\HR\FollowUpController::class, 'claimLock'])->name('follow-up.claim');
    Route::post('/follow-up/{cycle}/release', [\App\Http\Controllers\HR\FollowUpController::class, 'releaseLock'])->name('follow-up.release');
    Route::post('/follow-up/{cycle}/heartbeat', [\App\Http\Controllers\HR\FollowUpController::class, 'heartbeat'])->name('follow-up.heartbeat');
    Route::post('/follow-up/{cycle}/force-release', [\App\Http\Controllers\HR\FollowUpController::class, 'forceRelease'])->name('follow-up.force-release');

    // Log Aktivitas Terpusat -> admin.activity-logs.*
    Route::get('/activity-logs', [\App\Http\Controllers\Admin\ActivityLogController::class, 'index'])->name('activity-logs.index');
    Route::get('/activity-logs/{activityLog}', [\App\Http\Controllers\Admin\ActivityLogController::class, 'show'])->name('activity-logs.show');

    // Pengaturan Penyimpanan Cloud Google Drive -> admin.cloud-storage.*
    Route::prefix('cloud-storage')->name('cloud-storage.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\CloudStorageSettingController::class, 'index'])->name('index');
        Route::put('/', [\App\Http\Controllers\Admin\CloudStorageSettingController::class, 'update'])->name('update');
        Route::post('/test', [\App\Http\Controllers\Admin\CloudStorageSettingController::class, 'test'])->name('test');
    });

    // Perpustakaan Aset Sistem (Manajemen Admin) -> admin.system-assets.*
    Route::prefix('system-assets')->name('system-assets.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SystemAssetController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Admin\SystemAssetController::class, 'store'])->name('store');
        Route::post('/{systemAsset}/replace-version', [\App\Http\Controllers\Admin\SystemAssetController::class, 'replaceVersion'])->name('replace-version');
        Route::post('/{systemAsset}/toggle-active', [\App\Http\Controllers\Admin\SystemAssetController::class, 'toggleActive'])->name('toggle-active');
        Route::post('/{systemAsset}/set-default', [\App\Http\Controllers\Admin\SystemAssetController::class, 'setDefault'])->name('set-default');
        Route::post('/{systemAsset}/sync-drive', [\App\Http\Controllers\Admin\SystemAssetController::class, 'syncDrive'])->name('sync-drive');
        Route::delete('/{systemAsset}', [\App\Http\Controllers\Admin\SystemAssetController::class, 'destroy'])->name('destroy');
    });

    // Integrasi Google Spreadsheet -> admin.spreadsheet-connections.*
    Route::prefix('spreadsheet-connections')->name('spreadsheet-connections.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'index'])->name('index');
        Route::get('/{spreadsheetConnection}', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'show'])->name('show');
        Route::get('/{spreadsheetConnection}/edit', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'edit'])->name('edit');
        Route::put('/{spreadsheetConnection}', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'update'])->name('update');
        Route::post('/{spreadsheetConnection}/toggle', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'toggle'])->name('toggle');
        Route::post('/{spreadsheetConnection}/test', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'test'])->name('test');
        Route::post('/{spreadsheetConnection}/sync-rows/{spreadsheetRowSync}/retry', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'retryRow'])->name('retry-row');
        Route::post('/{spreadsheetConnection}/retry-failed', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'retryAllFailed'])->name('retry-failed');
        Route::post('/{spreadsheetConnection}/schemas', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'storeSchema'])->name('schemas.store');
        Route::post('/{spreadsheetConnection}/schemas/{schemaVersion}/activate', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'activateSchema'])->name('schemas.activate');
        Route::post('/{spreadsheetConnection}/schemas/{schemaVersion}/deactivate', [\App\Http\Controllers\Admin\SpreadsheetConnectionController::class, 'deactivateSchema'])->name('schemas.deactivate');
    });

    // Manajemen Template MOU -> admin.mou-templates.*
    Route::prefix('mou-templates')->name('mou-templates.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\MouTemplateController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Admin\MouTemplateController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Admin\MouTemplateController::class, 'store'])->name('store');
        Route::post('/upload-media', [\App\Http\Controllers\Admin\MouTemplateController::class, 'uploadMedia'])->name('upload-media');
        Route::get('/{mouTemplate}/edit', [\App\Http\Controllers\Admin\MouTemplateController::class, 'edit'])->name('edit');
        Route::put('/{mouTemplate}', [\App\Http\Controllers\Admin\MouTemplateController::class, 'update'])->name('update');
        Route::get('/{mouTemplate}/preview', [\App\Http\Controllers\Admin\MouTemplateController::class, 'preview'])->name('preview');
        Route::get('/{mouTemplate}/preview-pdf', [\App\Http\Controllers\Admin\MouTemplateController::class, 'previewPdf'])->name('preview-pdf');
        Route::post('/{mouTemplate}/toggle-active', [\App\Http\Controllers\Admin\MouTemplateController::class, 'toggleActive'])->name('toggle-active');
        Route::post('/{mouTemplate}/set-default', [\App\Http\Controllers\Admin\MouTemplateController::class, 'setDefault'])->name('set-default');
        Route::post('/{mouTemplate}/duplicate', [\App\Http\Controllers\Admin\MouTemplateController::class, 'duplicate'])->name('duplicate');
        Route::post('/{mouTemplate}/archive', [\App\Http\Controllers\Admin\MouTemplateController::class, 'archive'])->name('archive');
        Route::post('/{mouTemplate}/publish', [\App\Http\Controllers\Admin\MouTemplateController::class, 'publish'])->name('publish');
        Route::delete('/{mouTemplate}', [\App\Http\Controllers\Admin\MouTemplateController::class, 'destroy'])->name('destroy');
    });

    // Manajemen Dokumen MOU -> admin.mous.*
    Route::prefix('mous')->name('mous.')->group(function () {
        Route::get('/', [\App\Http\Controllers\HR\MouController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\HR\MouController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\HR\MouController::class, 'store'])->name('store');
        Route::get('/{mou}', [\App\Http\Controllers\HR\MouController::class, 'show'])->name('show');
        Route::get('/{mou}/print', [\App\Http\Controllers\HR\MouController::class, 'print'])->name('print');
        Route::get('/{mou}/download-pdf', [\App\Http\Controllers\HR\MouController::class, 'downloadPdf'])->name('download-pdf');
        Route::post('/{mou}/delivered', [\App\Http\Controllers\HR\MouController::class, 'markDelivered'])->name('delivered');
        Route::post('/{mou}/upload-signed', [\App\Http\Controllers\HR\MouController::class, 'uploadSigned'])->name('upload-signed');
        Route::get('/{mou}/download-signed', [\App\Http\Controllers\HR\MouController::class, 'downloadSigned'])->name('download-signed');
        Route::post('/{mou}/cancel', [\App\Http\Controllers\HR\MouController::class, 'cancel'])->name('cancel');
        Route::post('/{mou}/sync-drive', [\App\Http\Controllers\HR\MouController::class, 'syncDrive'])->name('sync-drive');
    });

    // Ekspor Arsip Tahunan -> admin.archive-exports.*
    Route::prefix('archive-exports')->name('archive-exports.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\ArchiveExportController::class, 'index'])->name('index');
        Route::get('/preview', [\App\Http\Controllers\Admin\ArchiveExportController::class, 'preview'])->name('preview');
        Route::post('/generate', [\App\Http\Controllers\Admin\ArchiveExportController::class, 'generate'])->name('generate');
        Route::get('/{archiveExport}/download', [\App\Http\Controllers\Admin\ArchiveExportController::class, 'download'])->name('download');
        Route::post('/{archiveExport}/sync-drive', [\App\Http\Controllers\Admin\ArchiveExportController::class, 'syncDrive'])->name('sync-drive');
        Route::delete('/{archiveExport}', [\App\Http\Controllers\Admin\ArchiveExportController::class, 'destroy'])->name('destroy');
    });

    // Impor Data Legacy -> admin.legacy-import.*
    Route::prefix('legacy-import')->name('legacy-import.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\LegacyImportController::class, 'index'])->name('index');
        Route::post('/preview', [\App\Http\Controllers\Admin\LegacyImportController::class, 'preview'])->name('preview');
        Route::post('/commit', [\App\Http\Controllers\Admin\LegacyImportController::class, 'commit'])->name('commit');
        Route::post('/cancel', [\App\Http\Controllers\Admin\LegacyImportController::class, 'cancel'])->name('cancel');
        Route::get('/template', [\App\Http\Controllers\Admin\LegacyImportController::class, 'downloadTemplate'])->name('template');
    });
});

/* =================== OPERATIONAL ROUTES (Admin & HR Staff) =================== */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin,hr_staff', 'prevent-back'])->group(function () {

    // Preview & API Kontrak Aset Sistem (Admin & HR Staff untuk Editor Dokumen & MoU)
    Route::prefix('system-assets')->name('system-assets.')->group(function () {
        Route::get('/{systemAsset}/preview', [\App\Http\Controllers\Admin\SystemAssetController::class, 'preview'])->name('preview');
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\Api\AssetApiController::class, 'index'])->name('index');
            Route::get('/resolve', [\App\Http\Controllers\Admin\Api\AssetApiController::class, 'resolve'])->name('resolve');
            Route::get('/{idOrUuid}', [\App\Http\Controllers\Admin\Api\AssetApiController::class, 'show'])->name('show');
        });
    });

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

        // Update status & data (bulk routes must be before wildcard /{intern} routes)
        Route::patch('/bulk/status', [InternController::class, 'bulkUpdateStatus'])->name('status.bulk');
        Route::patch('/{intern}/status', [InternController::class, 'updateStatus'])->name('status.update');
        Route::patch('/{intern}/followup-status', [InternController::class, 'updateFollowupStatus'])->name('followup.update');
        Route::patch('/{intern}/custom-answers', [InternController::class, 'updateCustomAnswers'])->name('custom-answers.update');
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

        // Arsip pendaftaran dihapus
        Route::get('/trashed', [InternPageController::class, 'trashed'])->name('trashed');

        // Hapus & Restore intern
        Route::delete('/{intern}', [InternController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/restore', [InternController::class, 'restore'])->name('restore');
    });

    // API Select interns & JSON -> admin.interns.search, admin.interns.api
    Route::get('/interns/search', [InternApiController::class, 'search'])->name('interns.search');
    Route::get('/interns.json',   [InternApiController::class, 'index'])->name('interns.api');

    // Generate dokumen (MoU / LOA / SKL / Sertifikat / Penilaian / Rekomendasi) → Universal Wizard Endpoints
    Route::post('/interns/generate-doc', [\App\Http\Controllers\Admin\GenerateDocController::class, 'generate'])
        ->name('interns.generate.doc');
    Route::post('/interns/generate-doc/bulk', [\App\Http\Controllers\Admin\GenerateDocController::class, 'generateBulk'])
        ->name('interns.generate.doc.bulk');
    Route::get('/interns/generate-wizard/preflight', [\App\Http\Controllers\Admin\GenerateDocController::class, 'preflight'])
        ->name('interns.generate.wizard.preflight');

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

    // Review & Raw Preview SKL
    Route::get('/skl/generate/{intern}', [SKLController::class, 'review'])->name('skl.review');
    Route::get('/skl/raw/{intern}', [SKLController::class, 'rawPreview'])->name('skl.raw');

    // Preview untuk panel editor (dipanggil dari iframe)
    Route::get('/skl/preview', [SKLController::class, 'preview'])->name('skl.preview');

    // Download SKL atas nama pemagang (khusus admin)
    Route::get('/skl/download/{user}', [SKLController::class, 'download'])
        ->name('skl.download.for_user');

});





Route::middleware(['auth', 'role:admin,hr_staff'])->group(function () {
    // LOA Administratif (role:admin,hr_staff)
    Route::get('/admin/loa/generate/{intern}', [LoaController::class, 'review'])->name('admin.loa.review');
    Route::get('/admin/loa/raw/{intern}', [LoaController::class, 'rawPreview'])->name('admin.loa.raw');
    Route::get('/admin/loa/interns', [LoaController::class, 'indexInterns'])->name('admin.loa.interns');
    Route::post('/admin/loa/generate', [LoaController::class, 'generate'])->name('admin.loa.generate'); // single
    Route::post('/admin/loa/generate-batch', [LoaController::class, 'generateBatch'])->name('admin.loa.generateBatch'); // multiple (legacy)
    Route::get('/admin/loa/download/{intern}', [LoaController::class, 'downloadSingle'])->name('admin.loa.download');
    Route::get('/admin/loa/interns-by-brand', [LoaController::class, 'getInternsByBrand'])->name('admin.loa.interns_by_brand');
    Route::post('/admin/loa/generate-brand', [LoaController::class, 'generateForBrand'])->name('admin.loa.generate_brand');
});

Route::middleware(['auth', 'role:admin,hr_staff'])->prefix('admin')->name('admin.')->group(function () {
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

/* =================== HR STAFF ROUTES =================== */
Route::middleware(['auth', 'active', 'role:hr_staff,admin'])->prefix('hr')->name('hr.')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\HR\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/', fn() => redirect()->route('hr.dashboard'));
    Route::get('/institutions', [\App\Http\Controllers\Admin\InstitutionController::class, 'index'])->name('institutions.index');
    Route::get('/institutions/{institution}', [\App\Http\Controllers\Admin\InstitutionController::class, 'show'])->name('institutions.show');
    Route::post('/institutions/{institution}/contacts', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'store'])->name('institutions.contacts.store');
    Route::put('/institution-contacts/{contact}', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'update'])->name('institution-contacts.update');
    Route::post('/institution-contacts/{contact}/toggle-status', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'toggleStatus'])->name('institution-contacts.toggle-status');
    Route::post('/institution-contacts/{contact}/verify', [\App\Http\Controllers\Admin\InstitutionContactController::class, 'verify'])->name('institution-contacts.verify');

    // Review Kontak Pembimbing -> hr.contact-review.*
    Route::get('/contact-review', [\App\Http\Controllers\Admin\ContactReviewController::class, 'index'])->name('contact-review.index');
    Route::post('/contact-review/{registration}/link', [\App\Http\Controllers\Admin\ContactReviewController::class, 'linkExisting'])->name('contact-review.link');
    Route::post('/contact-review/{registration}/create-and-verify', [\App\Http\Controllers\Admin\ContactReviewController::class, 'createAndVerify'])->name('contact-review.create-and-verify');

    // Follow-up Operasional HR -> hr.follow-up.*
    Route::get('/follow-up', [\App\Http\Controllers\HR\FollowUpController::class, 'index'])->name('follow-up.index');
    Route::get('/follow-up/create', [\App\Http\Controllers\HR\FollowUpController::class, 'create'])->name('follow-up.create');
    Route::post('/follow-up', [\App\Http\Controllers\HR\FollowUpController::class, 'store'])->name('follow-up.store');
    Route::get('/follow-up/{cycle}', [\App\Http\Controllers\HR\FollowUpController::class, 'show'])->name('follow-up.show');
    Route::post('/follow-up/{cycle}/attempts', [\App\Http\Controllers\HR\FollowUpController::class, 'recordAttempt'])->name('follow-up.attempts.store');
    Route::post('/follow-up/{cycle}/complete', [\App\Http\Controllers\HR\FollowUpController::class, 'complete'])->name('follow-up.complete');
    Route::post('/follow-up/{cycle}/claim', [\App\Http\Controllers\HR\FollowUpController::class, 'claimLock'])->name('follow-up.claim');
    Route::post('/follow-up/{cycle}/release', [\App\Http\Controllers\HR\FollowUpController::class, 'releaseLock'])->name('follow-up.release');
    Route::post('/follow-up/{cycle}/heartbeat', [\App\Http\Controllers\HR\FollowUpController::class, 'heartbeat'])->name('follow-up.heartbeat');

    // Dokumen MOU Kemitraan -> hr.mous.*
    Route::prefix('mous')->name('mous.')->group(function () {
        Route::get('/', [\App\Http\Controllers\HR\MouController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\HR\MouController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\HR\MouController::class, 'store'])->name('store');
        Route::get('/{mou}', [\App\Http\Controllers\HR\MouController::class, 'show'])->name('show');
        Route::get('/{mou}/print', [\App\Http\Controllers\HR\MouController::class, 'print'])->name('print');
        Route::get('/{mou}/download-pdf', [\App\Http\Controllers\HR\MouController::class, 'downloadPdf'])->name('download-pdf');
        Route::post('/{mou}/delivered', [\App\Http\Controllers\HR\MouController::class, 'markDelivered'])->name('delivered');
        Route::post('/{mou}/upload-signed', [\App\Http\Controllers\HR\MouController::class, 'uploadSigned'])->name('upload-signed');
        Route::get('/{mou}/download-signed', [\App\Http\Controllers\HR\MouController::class, 'downloadSigned'])->name('download-signed');
        Route::post('/{mou}/cancel', [\App\Http\Controllers\HR\MouController::class, 'cancel'])->name('cancel');
        Route::post('/{mou}/sync-drive', [\App\Http\Controllers\HR\MouController::class, 'syncDrive'])->name('sync-drive');
    });
});


Route::middleware(['auth', 'role:admin,hr_staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('generate-pdf', [SuratPenilaianController::class, 'showForm'])->name('generateForm');
    Route::post('generate-pdf', [SuratPenilaianController::class, 'generatePdf'])->name('generatePdf');
});


Route::middleware(['auth', 'role:admin,hr_staff'])
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


// Endpoint log download kartu anggota (khusus pemagang login)
Route::post('/log-download', [MembercardController::class, 'logDownload'])
    ->name('log.download')
    ->middleware(['auth', 'role:pemagang', 'throttle:30,1']);

// Unduh berkas dokumen pendaftaran privat (hanya pemilik atau admin/hr_staff)
Route::get('/registration/documents/{attachment}', [PemagangRegistration::class, 'downloadDocument'])
    ->name('pemagang.registration.attachment.download')
    ->middleware('auth');

/* =================== PEMAGANG & USER ROUTES =================== */
// Pendaftaran & Pengaturan Akun (diakses oleh user calon pemagang dan pemagang aktif)
Route::middleware(['auth', 'role:pemagang,user'])->prefix('pemagang')->name('pemagang.')->group(function () {
    // Form Pendaftaran
    Route::get('/daftar', [PemagangRegistration::class, 'showForm'])->name('registration.form');
    Route::post('/daftar', [PemagangRegistration::class, 'store'])->name('registration.store');
    Route::post('/daftar/draft', [PemagangRegistration::class, 'saveDraft'])->name('registration.draft');
    Route::delete('/daftar/attachments/{attachment}', [PemagangRegistration::class, 'deleteAttachment'])->name('registration.attachment.delete');

    // Pengaturan Akun
    Route::get('/pengaturan', [PemagangSettings::class, 'index'])->name('settings');
    Route::put('/pengaturan', [PemagangSettings::class, 'update'])->name('settings.update');
});

// Modul Pemagang Aktif / Selesai (khusus role:pemagang)
Route::middleware(['auth', 'role:pemagang'])->prefix('pemagang')->name('pemagang.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [PemagangDashboard::class, 'index'])->name('dashboard');

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

    // Webinar
    Route::get('/webinar', [\App\Http\Controllers\Pemagang\WebinarController::class, 'index'])->name('webinar.index');
    Route::get('/webinar/{webinar}', [\App\Http\Controllers\Pemagang\WebinarController::class, 'show'])->name('webinar.show');
    Route::post('/webinar/{webinar}/upload-proof', [\App\Http\Controllers\Pemagang\WebinarController::class, 'uploadProof'])->name('webinar.upload_proof');
});