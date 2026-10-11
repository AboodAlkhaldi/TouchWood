<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\ReleaseExpiredHolds;

use Carbon\CarbonImmutable;
use Modules\Inventory\Application\InventoryApiImpl;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Domain\Repository\Holds;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Expired holds freed** (inventory.md §1.4, §3), the system's job every minute (owner, 2026-10-11):
 * each order whose hold's expiry has passed has its open lines freed, as EXPIRED - each in its own
 * transaction, so one order never holds back the rest. Safe to repeat.
 */
final readonly class ReleaseExpiredHoldsHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = InventoryPermissions::HOLDS_EXPIRE;

    public function __construct(
        private Authorizer $authorizer,
        private Holds $holds,
        private InventoryApiImpl $api,
    ) {}

    /**
     * @return int the orders freed
     *
     * @throws Unauthorized
     */
    public function handle(ReleaseExpiredHolds $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $orders = $this->holds->expiredOrderIds(CarbonImmutable::now()->toDateTimeImmutable());

        foreach ($orders as $orderId) {
            $this->api->expire($orderId);
        }

        return count($orders);
    }
}
