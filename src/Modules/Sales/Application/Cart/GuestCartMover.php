<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Cart;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\RemovedLine;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Domain\ValueObject\StoreId;

/**
 * A guest who signed in or registered (sales.md §1.1; access.md §1.7): each of the guest's carts, in
 * every store, **moves** to the customer — or, where the customer already has a cart there, **merges**
 * into it, the same size and mode adding their quantities (my assumption, accepted with the spec). Then
 * the lines the account may not buy **leave, each with why**: wholesale for an individual (answer 32),
 * and products in categories the store keeps from the account's kind (owner, 2026-10-10). A company
 * keeps its wholesale lines. Done once however often told: the guest's carts are gone after the first.
 */
final readonly class GuestCartMover
{
    public function __construct(
        private Carts $carts,
        private AccessApi $access,
        private CatalogApi $catalog,
        private ConnectionInterface $db,
    ) {}

    public function move(string $guestId, string $customerId): void
    {
        $customer = $this->access->customer(strtolower($customerId));

        if ($customer === null) {
            return;
        }

        $customerId = strtolower($customer->id);
        $kind = $customer->accountType === AccountType::Company ? ShopperKind::Company : ShopperKind::Individual;

        $this->db->transaction(function () use ($guestId, $customerId, $kind): void {
            $now = CarbonImmutable::now();

            foreach ($this->carts->lockGuestCarts(strtolower($guestId)) as $guestCart) {
                $store = StoreId::fromString($guestCart->storeId);
                $existing = $this->carts->find($store, $customerId, null);

                if ($existing === null) {
                    $this->carts->giveToCustomer($guestCart->id, $customerId, $now);
                    $target = $guestCart->id;
                } else {
                    $target = $this->carts->lockOrCreate($store, $customerId, null, $now)->id;

                    foreach ($guestCart->lines as $line) {
                        $this->carts->add($target, $line->variantId, $line->mode, $line->quantity, $now);
                    }

                    $this->carts->delete($guestCart->id);
                }

                $merged = $this->carts->find($store, $customerId, null);

                if ($merged !== null) {
                    $this->removeWhatTheyMayNotBuy($merged, $store, $kind, $now);
                }
            }
        });
    }

    private function removeWhatTheyMayNotBuy(Cart $cart, StoreId $store, ShopperKind $kind, CarbonImmutable $now): void
    {
        if ($cart->lines === []) {
            return;
        }

        $productOf = [];

        foreach ($this->catalog->variants(array_values(array_unique(array_map(static fn ($line): string => $line->variantId, $cart->lines)))) as $id => $variant) {
            $productOf[$id] = $variant->productId;
        }

        $open = array_flip($this->catalog->productsOpenTo($store, $kind, array_values(array_unique($productOf))));
        $removed = [];

        foreach ($cart->lines as $line) {
            $reason = match (true) {
                $line->mode === SaleMode::Wholesale && $kind === ShopperKind::Individual => RemovedLine::WHOLESALE,
                ! isset($open[$productOf[$line->variantId] ?? '']) => RemovedLine::NOT_FOR_YOUR_ACCOUNT,
                default => null,
            };

            if ($reason !== null) {
                $this->carts->remove($cart->id, $line->variantId, $line->mode, $now);
                $removed[] = new RemovedLine($line->variantId, $line->mode, $line->quantity, $reason);
            }
        }

        if ($removed !== []) {
            $this->carts->setNotice($cart->id, [...$cart->notice, ...$removed], $now);
        }
    }
}
