<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // HOTFIX DB-SAFETY-P0: Enforce testing environment before bootstrapping
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');

        $app = require __DIR__.'/../bootstrap/app.php';

        if (is_file(dirname(__DIR__) . '/.env.testing')) {
            $app->loadEnvironmentFrom('.env.testing');
        }

        $app->make(Kernel::class)->bootstrap();

        // HOTFIX DB-SAFETY-P0: Explicitly lock database configuration to testing database with dedicated test user
        $app['config']->set('app.env', 'testing');
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.host', '127.0.0.1');
        $app['config']->set('database.connections.mysql.port', 3300);
        $app['config']->set('database.connections.mysql.database', 'internship_management_system_testing');
        $app['config']->set('database.connections.mysql.username', 'sertif_tester');
        $app['config']->set('database.connections.mysql.password', 'sertif_test_pass_2026');

        // Reconnect PDO to guarantee the configuration change takes effect immediately
        \Illuminate\Support\Facades\DB::purge('mysql');

        return $app;
    }
}
