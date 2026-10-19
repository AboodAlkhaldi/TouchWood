<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Cart;

use InvalidArgumentException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\GuestIdentity;
use Modules\Access\Public\Enums\AccountType;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who a cart belongs to (sales.md §1.1): the signed-in customer, under `sales.cart.manage`; or the guest
 * this browser is, under `sales.guest_cart.manage` — the guest's id created with the first line (Access's
 * `GuestIdentity`, access.md amendment 74), never on a mere look. Anyone else has no cart. In an open
 * store only: an off store is as if it were never there (platform.md §1.6).
 */
final readonly class CartBuyer
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private GuestIdentity $guests,
        private AccessApi $access,
        private PlatformApi $platform,
    ) {}

    /**
     * The store asked about, its permission checked for this buyer, and the buyer.
     *
     * @return array{StoreId, ?string, ?string, ?AccountType} the store, the customer or the guest, and the customer's account type
     *
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function of(string $storeId, bool $create): array
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSalesAttribute('store', 'not a store id');
        }

        $actor = $this->actors->current();
        $permission = match ($actor->type) {
            ActorType::Customer => SalesPermissions::CART_MANAGE,
            ActorType::Guest => SalesPermissions::GUEST_CART_MANAGE,
            default => throw new Unauthorized(SalesPermissions::CART_MANAGE),
        };
        $this->authorizer->authorize($permission, PermissionScope::store($store));

        if (! ($this->platform->store($store)->isActive ?? false)) {
            throw new InvalidSalesAttribute('store', 'no such store open');
        }

        if ($actor->type === ActorType::Customer) {
            $customerId = strtolower((string) $actor->id);

            return [$store, $customerId, null, $this->access->customer($customerId)?->accountType];
        }

        return [$store, null, $create ? $this->guests->ensure() : $this->guests->current(), null];
    }
}
