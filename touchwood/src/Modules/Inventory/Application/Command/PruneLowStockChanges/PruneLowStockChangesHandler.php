<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\PruneLowStockChanges;

use Carbon\CarbonImmutable;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Domain\Repository\LowSets;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The nightly removal** (inventory.md §1.7, amendment 1): the low-stock change log keeps twelve months,
 * as Ops keeps its daily reports' files - no report reaches further. System maintenance: it changes
 * nothing a person did, so it audits nothing.
 */
final readonly class PruneLowStockChangesHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = InventoryPermissions::LOW_CHANGES_PRUNE;

    /** How long an entry is kept. */
    public const int MONTHS = 12;

    public function __construct(
        private Authorizer $authorizer,
        private LowSets $sets,
    ) {}

    /**
     * @return int how many entries went
     *
     * @throws Unauthorized
     */
    public function handle(PruneLowStockChanges $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return $this->sets->prune(CarbonImmutable::now()->subMonthsNoOverflow(self::MONTHS)->toDateTimeImmutable());
    }
}
