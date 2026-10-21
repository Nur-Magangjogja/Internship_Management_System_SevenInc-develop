<?php

use App\Http\Controllers\Admin\DocumentTemplates\DocumentTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Document & Certificate Template Management Routes (Admin & HR)
|--------------------------------------------------------------------------
|
| Modul manajemen visual template dokumen (LOA, SKL, Sertifikat, Penilaian,
| Rekomendasi). Terisolasi dari routes/web.php untuk mencegah konflik Git.
|
*/

if (!Route::has('admin.document-templates.index')) {
    Route::middleware(['web', 'auth', 'role:admin,hr_staff', 'prevent-back'])
        ->prefix('admin/document-templates')
        ->name('admin.document-templates.')
        ->group(function () {
        // Katalog dan API pendukung editor
        Route::get('/api/assets', [DocumentTemplateController::class, 'apiAssets'])->name('api-assets');
        Route::post('/api/upload-background', [DocumentTemplateController::class, 'uploadBackground'])->name('api-upload-background');

        // CRUD Template Dokumen
        Route::get('/', [DocumentTemplateController::class, 'index'])->name('index');
        Route::get('/create', [DocumentTemplateController::class, 'create'])->name('create');
        Route::post('/', [DocumentTemplateController::class, 'store'])->name('store');
        
        Route::get('/{documentTemplate}/edit', [DocumentTemplateController::class, 'edit'])->name('edit');
        Route::put('/{documentTemplate}', [DocumentTemplateController::class, 'update'])->name('update');
        Route::delete('/{documentTemplate}', [DocumentTemplateController::class, 'destroy'])->name('destroy');

        // Aksi Khusus Dokumen & Versi (Lifecycle: Draft -> Published -> Active/Default -> Archived)
        Route::post('/{documentTemplate}/publish', [DocumentTemplateController::class, 'publish'])->name('publish');
        Route::post('/{documentTemplate}/archive', [DocumentTemplateController::class, 'archive'])->name('archive');
        Route::post('/{documentTemplate}/duplicate', [DocumentTemplateController::class, 'duplicate'])->name('duplicate');
        Route::post('/{documentTemplate}/set-default', [DocumentTemplateController::class, 'setDefault'])->name('set-default');
        Route::post('/{documentTemplate}/toggle-active', [DocumentTemplateController::class, 'toggleActive'])->name('toggle-active');
        Route::get('/{documentTemplate}/preview', [DocumentTemplateController::class, 'preview'])->name('preview');
        Route::get('/{documentTemplate}/preview-pdf', [DocumentTemplateController::class, 'previewPdf'])->name('preview-pdf');
        Route::get('/{documentTemplate}/versions', [DocumentTemplateController::class, 'versions'])->name('versions');
        Route::post('/{documentTemplate}/versions/{version}/restore', [DocumentTemplateController::class, 'restoreVersion'])->name('versions.restore');
    });
}
