<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\SyncNow;

use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\NotWired;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Application\Unauthorized;

/**
 * **Sync now** (sync.md §1.3, §3; owner, 2026-10-17 #21): a full read of the store, queued, asked by the
 * person who pressed it - without waiting for the provider's notice. One already waiting is the one
 * (asking twice never queues two).
 */
final readonly class SyncNowHandler
{
    public const string PERMISSION = SyncPermissions::READ_RUN;

    public function __construct(
        private ReportAccess $access,
        private Connections $connections,
        private Reads $reads,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|NotWired
     */
    public function handle(SyncNow $command): void
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);

        if (! $this->connections->exists($store)) {
            throw new NotWired;
        }

        $this->reads->queue($store, ReadKind::Now);
    }
}
