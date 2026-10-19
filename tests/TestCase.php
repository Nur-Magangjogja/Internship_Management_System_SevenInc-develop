<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
}, true, true);

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
