<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\PruneSyncLog;

use Carbon\CarbonImmutable;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Sync's log kept 90 days** (sync.md §1.6, §5; owner, 2026-10-13), the system's: reads finished before
 * then deleted, their lines with them, and entries closed before then. The price and stock histories -
 * Pricing's audit and Inventory's ledger - are kept for good, as always.
 */
final readonly class PruneSyncLogHandler
{
    public const string PERMISSION = SyncPermissions::LOG_PRUNE;

    public const int KEEP_DAYS = 90;

    public function __construct(
        private Authorizer $authorizer,
        private Reads $reads,
        private ReportEntries $entries,
    ) {}

    /**
     * @return array{int, int} reads and entries deleted
     *
     * @throws Unauthorized
     */
    public function handle(PruneSyncLog $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $before = CarbonImmutable::now()->subDays(self::KEEP_DAYS)->toDateTimeImmutable();

        return [$this->reads->pruneFinishedBefore($before), $this->entries->pruneClosedBefore($before)];
    }
}
