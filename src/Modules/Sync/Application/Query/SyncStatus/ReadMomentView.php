<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\SyncStatus;

/**
 * A read running or waiting, as the status line says it: its kind, and when it started (running) or was
 * asked (waiting) - "A read is running, started 2 minutes ago."
 */
final readonly class ReadMomentView
{
    public function __construct(
        public string $kind,
        public string $at,
    ) {}
}
