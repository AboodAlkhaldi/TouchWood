<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AttachLabels;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NotChosenInStore;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Model\StoreListing;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Shared\Application\Unauthorized;

/**
 * **A store's labels on a product** (catalog.md §1.3, §1.8, §3): `catalog.listing.labels` in that
 * store — "Clearance" in one store need not show in another. At most ten, each once; a label newly
 * attached must be active, one attached before may stay after it was deactivated (amendment 4(e)).
 * Each label read with its row locked, so one deleted meanwhile is either seen gone or sees itself
 * attached.
 * Only for a product the store has chosen (`NotChosenInStore`).
 */
final readonly class AttachLabelsHandler
{
    public const string PERMISSION = CatalogPermissions::LISTING_LABELS;

    public function __construct(
        private StoreListingChange $change,
        private ProductRepository $products,
        private StoreListingRepository $listings,
        private LabelRepository $labels,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|NotChosenInStore|ProductNotFound|TooMany|Unauthorized
     */
    public function handle(AttachLabels $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        StoreListing::checkLabelCount(count($command->labelIds));

        $labelIds = [];

        foreach ($command->labelIds as $labelId) {
            if (! is_string($labelId)) {
                throw new InvalidCatalogAttribute('labels', 'labels, by their ids');
            }

            $labelIds[] = strtolower($labelId);
        }

        $this->change->run(function () use ($command, $store, $labelIds): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $listing = $this->listings->of($store->value, $product->id());
            $held = $listing->labelIds();

            foreach ($labelIds as $labelId) {
                $label = $this->labels->byId($labelId) ?? throw new ListItemNotFound($labelId);

                if (! $label->isActive() && ! in_array($label->id(), $held, true)) {
                    throw new ListItemInactive;
                }
            }

            $listing->attachLabels($labelIds);
            $entry = ListAudit::changed('listing', 'labels_attached', $product->id(), $listing->pullChanges(), $listing->snapshot(), $store->value);

            if ($entry === null) {
                return [null, []];
            }

            $this->listings->save($listing);

            return [null, [$entry]];
        });
    }
}
