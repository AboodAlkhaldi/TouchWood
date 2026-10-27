<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\SalesActions;

use Modules\Sales\Application\SalesPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * The jobs the reader holds in an order's store, for the screens to offer (sales.md §4.1): asked here, never
 * of the authorizer from a screen (tests/Architecture/AccessDecisionsTest).
 */
final readonly class SalesActionsForReader
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    public function forStore(StoreId $store): SalesActions
    {
        return new SalesActions(
            $this->holds(SalesPermissions::ORDER_APPROVE, $store),
            $this->holds(SalesPermissions::ORDER_EDIT, $store),
            $this->holds(SalesPermissions::ORDER_CANCEL, $store),
            $this->holds(SalesPermissions::ORDER_FULFIL, $store),
            $this->holds(SalesPermissions::PAYMENT_RECORD, $store),
            $this->holds(SalesPermissions::RETURN_MANAGE, $store),
        );
    }

    private function holds(string $permission, StoreId $store): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === null) {
            return true;
        }

        foreach ($stores as $held) {
            if ($held->equals($store)) {
                return true;
            }
        }

        return false;
    }
}
