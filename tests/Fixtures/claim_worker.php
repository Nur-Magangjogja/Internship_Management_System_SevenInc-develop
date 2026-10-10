<?php

// tests/Fixtures/claim_worker.php
// Dedicated isolated CLI worker for FollowUp locking concurrency testing

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
$appBootstrap = dirname(__DIR__, 2) . '/bootstrap/app.php';

if (!file_exists($autoload) || !file_exists($appBootstrap)) {
    echo json_encode([
        'status' => 500,
        'error'  => 'Bootstrap files not found',
    ]);
    exit(1);
}

require $autoload;
/** @var \Illuminate\Foundation\Application $app */
$app = require $appBootstrap;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$actualEnv = config('app.env');
$actualDb  = config('database.connections.' . config('database.default') . '.database');

// Mandatory Guardrail 3: Prove environment from inside the worker
if ($actualEnv !== 'testing' || $actualDb !== 'internship_management_system_testing') {
    echo json_encode([
        'worker'   => $argv[1] ?? 'unknown',
        'env'      => $actualEnv,
        'database' => $actualDb,
        'status'   => 500,
        'error'    => "FATAL: Worker must run against testing DB! Found Env: {$actualEnv}, DB: {$actualDb}",
    ]);
    exit(1);
}

$workerLabel = $argv[1] ?? 'worker';
$userId      = (int) ($argv[2] ?? 0);
$cycleId     = (int) ($argv[3] ?? 0);
$duration    = (int) ($argv[4] ?? 15);

$user  = \App\Models\User::find($userId);
$cycle = \App\Models\FollowUpCycle::find($cycleId);

if (!$user || !$cycle) {
    echo json_encode([
        'worker'   => $workerLabel,
        'env'      => $actualEnv,
        'database' => $actualDb,
        'status'   => 404,
        'error'    => "User [{$userId}] or Cycle [{$cycleId}] not found in testing database.",
    ]);
    exit(1);
}

$service = app(\App\Services\FollowUpService::class);

try {
    $service->claimLock($cycle, $user, $duration);
    echo json_encode([
        'worker'   => $workerLabel,
        'env'      => $actualEnv,
        'database' => $actualDb,
        'status'   => 200,
        'user_id'  => $user->id,
        'cycle_id' => $cycle->id,
    ]);
    exit(0);
} catch (\DomainException $e) {
    echo json_encode([
        'worker'   => $workerLabel,
        'env'      => $actualEnv,
        'database' => $actualDb,
        'status'   => 409,
        'user_id'  => $user->id,
        'cycle_id' => $cycle->id,
        'error'    => $e->getMessage(),
    ]);
    exit(2);
} catch (\Throwable $t) {
    echo json_encode([
        'worker'   => $workerLabel,
        'env'      => $actualEnv,
        'database' => $actualDb,
        'status'   => 500,
        'user_id'  => $user->id,
        'cycle_id' => $cycle->id,
        'error'    => $t->getMessage(),
    ]);
    exit(3);
}
