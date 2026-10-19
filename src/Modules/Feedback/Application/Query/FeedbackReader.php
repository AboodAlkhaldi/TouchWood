<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductCardDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StaffNames;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\StaffNameDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Feedback's pages show of other modules', **read in bulk** (feedback.md §2.3; handoff §5.4): a
 * page's products, variants, authors and staff each in a fixed number of reads, however many rows.
 */
final readonly class FeedbackReader
{
    public function __construct(
        private CatalogApi $catalog,
        private PlatformApi $platform,
        private AccessApi $access,
        private StaffNames $staffNames,
    ) {}

    /**
     * These products as cards read in one store.
     *
     * @param  'ar'|'en'  $locale
     * @param  list<string>  $productIds
     * @return array<string, FeedbackCard>
     */
    public function cards(StoreId $store, string $locale, array $productIds): array
    {
        return $this->cardsByStore($locale, [$store->value => $productIds])[$store->value] ?? [];
    }

    /**
     * Each store's cards — a wishlist, "my reviews": each product in the store it belongs to —
     * Catalog's two reads a store, then every photo in one read.
     *
     * @param  'ar'|'en'  $locale
     * @param  array<string, list<string>>  $productIdsByStore
     * @return array<string, array<string, FeedbackCard>> by store id, then product id
     */
    public function cardsByStore(string $locale, array $productIdsByStore): array
    {
        $found = [];
        $mediaIds = [];

        foreach ($productIdsByStore as $storeId => $productIds) {
            $found[$storeId] = $productIds === [] ? [] : $this->catalog->productCards(StoreId::fromString($storeId), $locale, array_values(array_unique($productIds)));

            foreach ($found[$storeId] as $card) {
                if ($card->photoMediaId !== null) {
                    $mediaIds[] = strtolower($card->photoMediaId);
                }
            }
        }

        $photos = $mediaIds === [] ? [] : $this->platform->mediaUrlsOf(array_values(array_unique($mediaIds)));
        $cards = [];

        foreach ($found as $storeId => $storeCards) {
            $cards[$storeId] = array_map(static fn (ProductCardDto $card): FeedbackCard => self::toCard($card, $photos), $storeCards);
        }

        return $cards;
    }

    /**
     * One card; a product Catalog no longer has reads as removed.
     *
     * @param  array<string, FeedbackCard>  $cards
     */
    public static function card(array $cards, string $productId): FeedbackCard
    {
        return $cards[$productId] ?? new FeedbackCard($productId, '', null, null, false, false, true);
    }

    /**
     * These variants' values in the language.
     *
     * @param  'ar'|'en'  $locale
     * @param  list<string>  $variantIds
     * @return array<string, list<VariantValueRow>>
     */
    public function variants(string $locale, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $values = [];

        foreach ($this->catalog->variants(array_values(array_unique($variantIds))) as $id => $variant) {
            $values[$id] = array_map(static fn ($value): VariantValueRow => new VariantValueRow($value->attribute->in($locale), $value->value->in($locale)), $variant->values);
        }

        return $values;
    }

    /**
     * Authors in their public form (§1.1), in one read.
     *
     * @param  list<string>  $customerIds
     * @return array<string, AuthorName>
     */
    public function authors(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $names = [];

        foreach ($this->access->customers(array_values(array_unique($customerIds))) as $id => $customer) {
            $names[strtolower($id)] = $customer->anonymized
                ? new AuthorName('', '', true)
                : new AuthorName($customer->firstName, mb_substr(trim($customer->lastName), 0, 1), false);
        }

        return $names;
    }

    /** @param  array<string, AuthorName>  $names */
    public static function author(array $names, string $customerId): AuthorName
    {
        return $names[$customerId] ?? new AuthorName('', '', true);
    }

    /**
     * Staff as the reader may be shown them (a Super Admin withheld), in one read.
     *
     * @param  list<string>  $staffIds
     * @return array<string, StaffNameDto>
     */
    public function staff(array $staffIds): array
    {
        return $staffIds === [] ? [] : $this->staffNames->forReader(array_values(array_unique($staffIds)));
    }

    /**
     * Every store, by id, in Platform's order.
     *
     * @return array<string, StoreDto>
     */
    public function stores(): array
    {
        $stores = [];

        foreach ($this->platform->allStores() as $store) {
            $stores[strtolower($store->id)] = $store;
        }

        return $stores;
    }

    /**
     * @param  array<string, MediaUrlsDto>  $photos
     */
    private static function toCard(ProductCardDto $card, array $photos): FeedbackCard
    {
        $urls = $card->photoMediaId === null ? null : ($photos[strtolower($card->photoMediaId)] ?? null);

        return new FeedbackCard(
            $card->productId,
            $card->name,
            $card->slug,
            $urls === null || $urls->variants === [] ? null : $urls->variants,
            $card->sold,
            $card->stage === ProductStage::Archived,
        );
    }
}
