<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SwitchOnStoreFillItems;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\StoreFillItem;
use Modules\Catalog\Application\Import\StoreFills;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Public\Enums\ProductStage;
use Shared\Application\Unauthorized;

/**
 * **Switching on a store file's items** (catalog.md §1.3; amendment 6(g), (h)): `catalog.listing.fill`
 * in the file's store — admin roles only. Each open item whose code a **ready** product holds chooses,
 * in that store, **the variant carrying that code** (amendment 16(a)), not archived — as the store's own choice does
 * (§1.3), first chosen selling retail only; the product itself is never changed. Read under the
 * products' lock, so a product archived meanwhile is never switched on. The variants taken up are
 * sent as `StoreListingChanged`; each product's choice is audited in the store, and the step once.
 *
 * Chosen by name, an item that cannot be switched on — an unknown code, a product not ready or
 * archived — is named and nothing changes; "every one ready" leaves the rest. An item whose variant
 * is on already is marked on.
 */
final readonly class SwitchOnStoreFillItemsHandler
{
    public const string PERMISSION = StoreFills::PERMISSION;

    public function __construct(
        private StoreFills $fills,
        private ProductRepository $products,
        private VariantRepository $variants,
        private StoreListingRepository $listings,
        private ProductEvents $events,
        private ListingRows $listingRows,
    ) {}

    /**
     * @return int how many items it switched on
     *
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(SwitchOnStoreFillItems $command): int
    {
        $store = $this->fills->authorize($command->importId);

        return $this->fills->run($command->importId, function (ImportHeader $import, array $items) use ($command, $store): array {
            $all = $command->itemIds === null;
            $entries = [];
            $products = [];
            $on = [];

            foreach (StoreFills::chosen($command->itemIds, $items) as $item) {
                $chosen = $item->state === StoreFillItem::OPEN ? $this->variantOf($item->code) : null;

                if ($chosen === null) {
                    if ($all) {
                        continue;
                    }

                    throw new InvalidCatalogAttribute("item {$item->number}", 'an open item whose code a ready product holds');
                }

                [$productId, $variantId] = $chosen;
                $listing = $this->listings->of($store, $productId);
                $wasActive = $listing->activeVariantIds();
                $listing->choose([$variantId], true);
                $entry = ListAudit::changed('listing', 'chosen', $productId, $listing->pullChanges(), $listing->snapshot(), $store);

                if ($entry !== null) {
                    $this->listings->save($listing);
                    $entries[] = $entry;
                    $products[$productId] = true;
                    $takenUp = array_values(array_diff($listing->activeVariantIds(), $wasActive));

                    if ($takenUp !== []) {
                        $this->events->storeListingChanged($store, $takenUp);
                    }
                }

                $this->fills->save($item->with($item->code, StoreFillItem::ON));
                $on[] = $item->number;
            }

            if ($products !== []) {
                $this->listingRows->refresh(array_keys($products));
            }

            if ($on !== []) {
                $entries[] = ListAudit::changed('store_fill', 'switched_on', $import->id, ['items' => null], ['items' => implode(', ', $on)], $store) ?? throw new LogicException('No change to record.');
            }

            return [count($on), $entries];
        });
    }

    /**
     * The ready product and its variant carrying the code, not archived — or null.
     *
     * @return array{string, string}|null
     */
    private function variantOf(string $code): ?array
    {
        $variant = $this->variants->carrying($code);
        $product = $variant === null || $variant->isArchived() ? null : $this->products->byId($variant->productId());

        return $variant === null || $product === null || $product->stage() !== ProductStage::Ready ? null : [$product->id(), $variant->id()];
    }
}
