<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOrderStores;

use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Query\Staff\OrderStores;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The store filter of Sales's order and return screens** (sales.md §1.12, §3.2): the stores where the
 * reader views orders, in the platform's order — an off one only while it holds an unfinished order, every
 * off one to a Super Admin.
 */
final readonly class ListOrderStoresHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private OrderStores $stores,
    ) {}

    /**
     * @return list<OrderStoreChoice>
     *
     * @throws Unauthorized
     */
    public function handle(ListOrderStores $query): array
    {
        $covered = $this->authorizer->storesWith(self::PERMISSION);

        if ($this->actors->current()->type !== ActorType::Staff || $covered === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->authorizer->authorize(self::PERMISSION, $covered === null ? PermissionScope::allStores() : PermissionScope::store($covered[0]));

        return array_map(static fn (StoreDto $store): OrderStoreChoice => new OrderStoreChoice($store->id, $store->code, $store->name, $store->isActive), $this->stores->listed($covered));
    }
}
