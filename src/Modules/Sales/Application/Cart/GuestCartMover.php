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
 * every store, **merges** into the customer's cart there — made now when they have none, so a newly
 * registered account's carts are the guest's moved — the same size and mode adding their quantities,
 * each line keeping when it was added (my assumption, accepted with the spec). The customer's cart is
 * locked or made under its own key, so a line the customer adds on another device at that moment is
 * never lost and never makes a second cart. Then the lines the account may not buy **leave, each with
 * why**: wholesale for an individual (answer 32), and products in categories the store keeps from the
 * account's kind (owner, 2026-10-10). A company keeps its wholesale lines. Done once however often told:
 * the guest's carts are gone after the first.
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
                $target = $this->carts->lockOrCreate($store, $customerId, null, $now);

                foreach ($guestCart->lines as $line) {
                    $this->carts->add($target->id, $line->variantId, $line->mode, $line->quantity, $now, $line->addedAt);
                }

                $this->carts->delete($guestCart->id);
                $merged = $this->carts->lock($store, $customerId, null);

                if ($merged !== null) {
                    $this->removeWhatTheyMayNotBuy($merged, $target->notice, $store, $kind, $now);
                }
            }
        });
    }

    /**
     * @param  list<RemovedLine>  $earlier  what the customer's cart was still telling before the merge
     */
    private function removeWhatTheyMayNotBuy(Cart $cart, array $earlier, StoreId $store, ShopperKind $kind, CarbonImmutable $now): void
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
            // A size Catalog deleted this moment: its lines go with it (the key cascades), nothing to tell.
            if (! isset($productOf[$line->variantId])) {
                continue;
            }

            $reason = match (true) {
                $line->mode === SaleMode::Wholesale && $kind === ShopperKind::Individual => RemovedLine::WHOLESALE,
                ! isset($open[$productOf[$line->variantId]]) => RemovedLine::NOT_FOR_YOUR_ACCOUNT,
                default => null,
            };

            if ($reason !== null) {
                $this->carts->remove($cart->id, $line->variantId, $line->mode, $now);
                $removed[] = new RemovedLine($line->variantId, $line->mode, $line->quantity, $reason);
            }
        }

        if ($removed !== []) {
            $this->carts->setNotice($cart->id, [...$earlier, ...$removed], $now);
        }
    }
}
