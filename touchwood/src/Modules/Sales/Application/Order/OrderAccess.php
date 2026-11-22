<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Which orders staff may reach (sales.md §3, §7): in the stores where they hold the job — an order of a
 * store they do not cover is answered as no order (`OrderNotFound`), someone holding the job nowhere is
 * simply not allowed. A customer's own orders: `OwnOrderAccess`.
 */
final readonly class OrderAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Orders $orders,
    ) {}

    /**
     * The order's store, the staff member's permission checked there.
     *
     * @return array{string, StoreId, string} the order, its store, and the staff member
     *
     * @throws Unauthorized|OrderNotFound
     */
    public function authorize(string $permission, string $orderId): array
    {
        $actor = $this->actors->current();
        $covered = $this->authorizer->storesWith($permission);

        if ($actor->type !== ActorType::Staff || $actor->id === null || $covered === []) {
            throw new Unauthorized($permission);
        }

        [$id, $store] = $this->found($orderId);

        if ($covered !== null && ! in_array($store->value, array_map(static fn (StoreId $one): string => $one->value, $covered), true)) {
            throw new OrderNotFound;
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));

        return [$id, $store, strtolower($actor->id)];
    }

    /**
     * @return array{string, StoreId}
     *
     * @throws OrderNotFound
     */
    public function found(string $orderId): array
    {
        $id = strtolower(trim($orderId));
        $store = Ulids::valid($id) ? $this->orders->storeOf($id) : null;

        return $store === null ? throw new OrderNotFound : [$id, StoreId::fromString($store)];
    }
}
