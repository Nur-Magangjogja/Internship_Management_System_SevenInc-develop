<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class DocumentTemplateServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services for document and certificate templates.
     */
    public function boot(): void
    {
        // Cegah duplikasi rute jika rute sudah didaftarkan lewat routes/web.php
        if (Route::has('admin.document-templates.index')) {
            return;
        }

        $routeFile = base_path('routes/document_templates.php');
        if (!file_exists($routeFile)) {
            $routeFile = dirname(__DIR__, 2) . '/routes/document_templates.php';
        }

        if (file_exists($routeFile)) {
            $this->loadRoutesFrom($routeFile);
        }
    }
}
