<?php

namespace App\Support\DatabaseSafety;

/**
 * Immutable snapshot of everything the manual reset gate needs to decide eligibility.
 */
final class ManualResetContext
{
    public function __construct(
        public readonly string $commandName,
        public readonly string $environment,
        public readonly string $connectionName,
        public readonly ?string $driver,
        public readonly ?string $host,
        public readonly ?string $configuredDatabase,
        public readonly ?string $actualDatabase,
        public readonly bool $directTerminalInvocation,
        public readonly bool $interactive,
        public readonly bool $stdinIsTty,
        public readonly bool $ciDetected,
    ) {}
}
