<?php

namespace Tests;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Fail-closed database safety guard executing before any traits (RefreshDatabase, DatabaseMigrations, etc.)
     */
    protected function setUpTraits()
    {
        DatabaseSafetyGuard::assertSafeForTesting();

        return parent::setUpTraits();
    }

    /**
     * Fail-closed database safety guard executing on test setup.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseSafetyGuard::assertSafeForTesting();
    }
}
