<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIsolationVerificationTest extends TestCase
{
    public function test_runtime_database_is_strictly_testing_database(): void
    {
        $this->assertEquals('testing', app()->environment(), 'App environment must be testing');

        $configuredDb = config('database.connections.mysql.database');
        $this->assertEquals(
            'internship_management_system_testing',
            $configuredDb,
            'Configured database name must be internship_management_system_testing'
        );

        $connectionDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'internship_management_system_testing',
            $connectionDb,
            'Active DB connection getDatabaseName() must be internship_management_system_testing'
        );

        $this->assertNotEquals(
            'internship_management_system',
            $connectionDb,
            'CRITICAL SAFETY GUARDRAIL: Active database connection must NEVER be the working database internship_management_system'
        );

        $actualDbFromPdo = DB::select('SELECT DATABASE() as current_db')[0]->current_db;
        $this->assertEquals(
            'internship_management_system_testing',
            $actualDbFromPdo,
            'Actual MySQL SELECT DATABASE() must return internship_management_system_testing'
        );
    }
}
