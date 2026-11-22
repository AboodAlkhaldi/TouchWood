<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Sales\Domain\Exception\OrderNotFound;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * A customer's own orders (sales.md §3, §7): only a signed-in customer, the permission checked in the
 * order's store; another customer's order is no order — asked under the order's lock by the command.
 */
final readonly class OwnOrderAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private OrderAccess $orders,
    ) {}

    /**
     * The order's store, the customer's permission checked there; whose it is is asked again under the
     * order's lock (`mine`).
     *
     * @return array{string, StoreId, string} the order, its store, and the customer
     *
     * @throws Unauthorized|OrderNotFound
     */
    public function authorize(string $permission, string $orderId): array
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        [$id, $store] = $this->orders->found($orderId);
        $this->authorizer->authorize($permission, PermissionScope::store($store));

        return [$id, $store, strtolower($actor->id)];
    }
}
