<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetSellingTerms;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\InvalidSellingTerms;
use Modules\Catalog\Domain\Exception\NotChosenInStore;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\SellingLimits;
use Shared\Application\Unauthorized;

/**
 * **Selling terms** (catalog.md §1.3, §3): `catalog.listing.selling` in that store — each variant
 * retail, wholesale or both, and the product's minimum and maximum for each mode, 1–100,000, a
 * maximum never below its minimum, a wholesale minimum while any variant sells wholesale
 * (`InvalidSellingTerms`). Only for a product the store has chosen (`NotChosenInStore`).
 */
final readonly class SetSellingTermsHandler
{
    public const string PERMISSION = CatalogPermissions::LISTING_SELLING;

    public function __construct(
        private StoreListingChange $change,
        private ProductRepository $products,
        private StoreListingRepository $listings,
        private VariantRepository $variants,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|InvalidSellingTerms|NotChosenInStore|ProductNotFound|Unauthorized|VariantNotFound
     */
    public function handle(SetSellingTerms $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $modes = self::modes($command->modes);
        $limits = SellingLimits::of($command->retailMinimum, $command->retailMaximum, $command->wholesaleMinimum, $command->wholesaleMaximum);

        $this->change->run(function () use ($command, $store, $modes, $limits): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $own = array_map(static fn ($variant): string => $variant->id(), $this->variants->ofProduct($product->id()));

            foreach (array_keys($modes) as $variantId) {
                if (! in_array($variantId, $own, true)) {
                    throw new VariantNotFound($variantId);
                }
            }

            $listing = $this->listings->of($store->value, $product->id());
            $listing->setTerms($modes, $limits);
            $entry = ListAudit::changed('listing', 'terms_set', $product->id(), $listing->pullChanges(), $listing->snapshot(), $store->value);

            if ($entry === null) {
                return [null, []];
            }

            $this->listings->save($listing);

            return [null, [$entry]];
        });
    }

    /**
     * The modes as the request sent them: for each variant, both switches, true or false.
     *
     * @param  array<array-key, mixed>  $modes
     * @return array<string, array{retail: bool, wholesale: bool}>
     *
     * @throws InvalidCatalogAttribute
     */
    private static function modes(array $modes): array
    {
        $parsed = [];

        foreach ($modes as $variantId => $mode) {
            if (! is_array($mode) || ! is_bool($mode['retail'] ?? null) || ! is_bool($mode['wholesale'] ?? null)) {
                throw new InvalidCatalogAttribute('modes', 'retail and wholesale switches, by variant');
            }

            $parsed[strtolower((string) $variantId)] = ['retail' => $mode['retail'], 'wholesale' => $mode['wholesale']];
        }

        return $parsed;
    }
}
