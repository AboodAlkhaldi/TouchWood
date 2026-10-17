<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\QueueScheduledReads;

use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The hour's and the night's reads** (sync.md §1.3), the system's: one of the kind queued for every
 * wired store, a switched-off one too (§1.1 rule 8). A store whose read of the kind still waits gets no
 * second (`reads_one_waiting`): a slow provider never piles reads up.
 */
final readonly class QueueScheduledReadsHandler
{
    public const string PERMISSION = SyncPermissions::READ_SCHEDULED;

    public function __construct(
        private Authorizer $authorizer,
        private Connections $connections,
        private Reads $reads,
    ) {}

    /**
     * @return int the stores read
     *
     * @throws Unauthorized
     */
    public function handle(QueueScheduledReads $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $kind = ReadKind::from($command->kind);
        $stores = $this->connections->wiredStores();

        foreach ($stores as $store) {
            $this->reads->queue(StoreId::fromString($store), $kind);
        }

        return count($stores);
    }
}
