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
use Throwable;

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

        $freed = 0;

        foreach ($orders as $orderId) {
            // One order's failure is reported and the rest are still freed: the earliest expiry comes
            // first every minute, so a failing one must not stop every later one.
            try {
                $this->api->expire($orderId);
                $freed++;
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $freed;
    }
}
