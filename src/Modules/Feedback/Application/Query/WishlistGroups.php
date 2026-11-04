<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use Modules\Platform\Public\Enums\ShopperKind;

/**
 * A wishlist as its pages show it (feedback.md §1.4): grouped by the store each item was saved in, in
 * the stores' own order, each product read in its own store — so "unavailable" is judged there,
 * whichever store is browsed (answer 17), and every item of an off store is unavailable; so is one sold out
 * or unpriced there for the customer whose list it is — as they see it, whoever is reading (owner, 2026-11-03;
 * catalog.md amendments 39(k), 40(e)) — which stays in the list.
 */
final readonly class WishlistGroups
{
    public function __construct(
        private FeedbackReader $reader,
    ) {}

    /**
     * @param  list<WishlistItem>  $items  newest saved first
     * @param  'ar'|'en'  $locale
     * @param  ShopperKind  $customer  the kind of customer whose list it is
     * @return list<WishlistGroup>
     */
    public function of(array $items, string $locale, ShopperKind $customer): array
    {
        $byStore = [];

        foreach ($items as $item) {
            $byStore[$item->storeId][] = $item->productId;
        }

        $cards = $this->reader->cardsByStore($locale, $byStore);
        $orderable = $this->reader->orderableNowByStore($customer, $byStore);
        $groups = [];

        foreach ($this->reader->stores() as $storeId => $store) {
            if (! isset($byStore[$storeId])) {
                continue;
            }

            $rows = [];

            foreach ($items as $item) {
                if ($item->storeId !== $storeId) {
                    continue;
                }

                $card = FeedbackReader::card($cards[$storeId] ?? [], $item->productId);
                $rows[] = new WishlistItemRow($card, $item->savedAt, ! $store->isActive || ! $card->sold || ! isset($orderable[$storeId][strtolower($item->productId)]));
            }

            $groups[] = new WishlistGroup($storeId, $store->name->in($locale), $store->isActive, $rows);
        }

        return $groups;
    }
}
