<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListCarriers;

use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Shipping\Public\Contracts\ShippingApi;
use Modules\Shipping\Public\Dto\CarrierDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **The store's carriers** for the Ship dialog (sales.md §3.2, §9.5 #3; gap 6.3): Shipping's, switched-on
 * first, **the order's country first** — staff may ship with any of them, or type "Other carrier" (an order to a
 * country none of them serves) — for staff who ship that store's orders.
 */
final readonly class ListCarriersHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private ShippingApi $shipping,
    ) {}

    /**
     * @return list<CarrierDto>
     *
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(ListCarriers $query): array
    {
        $id = strtolower(trim($query->storeId));
        $store = Ulids::valid($id) ? StoreId::fromString($id) : throw new InvalidSalesAttribute('store', 'a store');

        if ($this->actors->current()->type !== ActorType::Staff) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->authorizer->authorize($query->forEdit ? SalesPermissions::ORDER_EDIT : self::PERMISSION, PermissionScope::store($store));

        $country = $query->countryCode === null ? null : strtoupper(trim($query->countryCode));
        $carriers = $this->shipping->carriers($store);

        // Stable: within each part, Shipping's own order (switched-on first, each country's in turn).
        return [
            ...array_values(array_filter($carriers, static fn (CarrierDto $carrier): bool => $carrier->countryCode === $country)),
            ...array_values(array_filter($carriers, static fn (CarrierDto $carrier): bool => $carrier->countryCode !== $country)),
        ];
    }
}
