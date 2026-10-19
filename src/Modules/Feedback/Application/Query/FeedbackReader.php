<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StaffNames;
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
     * These products as cards read in one store (Catalog's two reads, then their photos in one).
     *
     * @param  'ar'|'en'  $locale
     * @param  list<string>  $productIds
     * @return array<string, FeedbackCard>
     */
    public function cards(StoreId $store, string $locale, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $found = $this->catalog->productCards($store, $locale, array_values(array_unique($productIds)));
        $photos = $this->platform->mediaUrlsOf(array_values(array_filter(array_map(static fn ($card): ?string => $card->photoMediaId === null ? null : strtolower($card->photoMediaId), $found))));
        $cards = [];

        foreach ($found as $id => $card) {
            $urls = $card->photoMediaId === null ? null : ($photos[strtolower($card->photoMediaId)] ?? null);
            $cards[$id] = new FeedbackCard(
                $id,
                $card->name,
                $card->slug,
                $urls === null || $urls->variants === [] ? null : $urls->variants,
                $card->sold,
                $card->stage === ProductStage::Archived,
            );
        }

        return $cards;
    }

    /**
     * Each store's cards, a store read once (a wishlist, "my reviews": each item in its own store).
     *
     * @param  'ar'|'en'  $locale
     * @param  array<string, list<string>>  $productIdsByStore
     * @return array<string, array<string, FeedbackCard>> by store id, then product id
     */
    public function cardsByStore(string $locale, array $productIdsByStore): array
    {
        $cards = [];

        foreach ($productIdsByStore as $storeId => $productIds) {
            $cards[$storeId] = $this->cards(StoreId::fromString($storeId), $locale, $productIds);
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
}
