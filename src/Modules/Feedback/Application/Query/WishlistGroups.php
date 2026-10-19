<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/**
 * A wishlist as its pages show it (feedback.md §1.4): grouped by the store each item was saved in, in
 * the stores' own order, each product read in its own store — so "unavailable" is judged there,
 * whichever store is browsed (answer 17), and every item of an off store is unavailable.
 */
final readonly class WishlistGroups
{
    public function __construct(
        private FeedbackReader $reader,
    ) {}

    /**
     * @param  list<WishlistItem>  $items  newest saved first
     * @param  'ar'|'en'  $locale
     * @return list<WishlistGroup>
     */
    public function of(array $items, string $locale): array
    {
        $byStore = [];

        foreach ($items as $item) {
            $byStore[$item->storeId][] = $item->productId;
        }

        $cards = $this->reader->cardsByStore($locale, $byStore);
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
                $rows[] = new WishlistItemRow($card, $item->savedAt, ! $store->isActive || ! $card->sold);
            }

            $groups[] = new WishlistGroup($storeId, $store->name->in($locale), $store->isActive, $rows);
        }

        return $groups;
    }
}
