<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\PruneOpsLog;

use Carbon\CarbonImmutable;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Repository\OpsLog;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The nightly removal** (ops.md §1.2, §5; owner, round 3 #14): inbox entries, sent messages and
 * handled events keep twelve months. System maintenance: it changes nothing a person did, so it
 * audits nothing - as Inventory's and Catalog's own prunes.
 */
final readonly class PruneOpsLogHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = OpsPermissions::LOG_PRUNE;

    /** How long a row is kept. */
    public const int MONTHS = 12;

    public function __construct(
        private Authorizer $authorizer,
        private OpsLog $log,
    ) {}

    /**
     * @return array{inbox_entries: int, sent_messages: int, handled_events: int} how many went from each
     *
     * @throws Unauthorized
     */
    public function handle(PruneOpsLog $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return $this->log->prune(CarbonImmutable::now()->subMonthsNoOverflow(self::MONTHS)->toDateTimeImmutable());
    }
}
