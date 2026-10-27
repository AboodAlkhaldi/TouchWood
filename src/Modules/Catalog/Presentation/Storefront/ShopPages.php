<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Storefront;

use Closure;
use Modules\Catalog\Application\Api\ApiReads;
use Modules\Catalog\Application\Products\InMemoryShopCardFacts;
use Modules\Catalog\Application\Products\InMemoryShopProductSections;
use Modules\Catalog\Application\Query\Shop\CardLabel;
use Modules\Catalog\Application\Query\Shop\CategoryFilters;
use Modules\Catalog\Application\Query\Shop\CategoryPage;
use Modules\Catalog\Application\Query\Shop\FilterChoice;
use Modules\Catalog\Application\Query\Shop\FilterGroup;
use Modules\Catalog\Application\Query\Shop\Moved;
use Modules\Catalog\Application\Query\Shop\ProductCard;
use Modules\Catalog\Application\Query\Shop\ShopCatalog;
use Modules\Catalog\Application\Query\Shop\ShopChoice;
use Modules\Catalog\Application\Query\Shop\ShopChoiceValue;
use Modules\Catalog\Application\Query\Shop\ShopFilters;
use Modules\Catalog\Application\Query\Shop\ShopPagesCache;
use Modules\Catalog\Application\Query\Shop\ShopSearch;
use Modules\Catalog\Application\Query\Shop\ShopVariant;
use Modules\Catalog\Application\Query\Shop\VariantDetail;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Presentation\Http\Resource\ShopAddressData;
use Modules\Catalog\Presentation\Http\Resource\ShopBrandPage;
use Modules\Catalog\Presentation\Http\Resource\ShopCardData;
use Modules\Catalog\Presentation\Http\Resource\ShopCardPriceData;
use Modules\Catalog\Presentation\Http\Resource\ShopCategoryPage;
use Modules\Catalog\Presentation\Http\Resource\ShopChoiceData;
use Modules\Catalog\Presentation\Http\Resource\ShopChoiceValueData;
use Modules\Catalog\Presentation\Http\Resource\ShopDetailData;
use Modules\Catalog\Presentation\Http\Resource\ShopFilterChoiceData;
use Modules\Catalog\Presentation\Http\Resource\ShopFilterGroupData;
use Modules\Catalog\Presentation\Http\Resource\ShopFiltersData;
use Modules\Catalog\Presentation\Http\Resource\ShopLabelData;
use Modules\Catalog\Presentation\Http\Resource\ShopLinkData;
use Modules\Catalog\Presentation\Http\Resource\ShopProductPage;
use Modules\Catalog\Presentation\Http\Resource\ShopSearchPage;
use Modules\Catalog\Presentation\Http\Resource\ShopSellingData;
use Modules\Catalog\Presentation\Http\Resource\ShopVariantData;
use Modules\Catalog\Presentation\Http\Resource\ShopWarrantyData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The shop's pages as their screens draw them (catalog.md §4.5): Catalog's reads, for the viewer
 * this request has (ShopVisit), shaped. Every page carries its canonical address and the other
 * language's (P19). **A product's page is kept** in the never-stale cache per store, language,
 * viewer and product (P21) — its address is not: it is the request's own.
 */
final readonly class ShopPages
{
    public function __construct(
        private ShopCatalog $catalog,
        private ShopSearch $search,
        private ShopVisit $visit,
        private ShopPagesCache $cache,
        private PlatformApi $platform,
        private InMemoryShopProductSections $sections,
        private InMemoryShopCardFacts $cardFacts,
        private ActorContext $actors,
        private ApiReads $reads,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public function category(StoreId $store, string $locale, string $slug, ShopFilters $filters, ?string $after): ShopCategoryPage|Moved|null
    {
        $viewer = $this->visit->viewer();
        $page = $this->catalog->category($store, $locale, $viewer, $slug, $filters, $after);

        if (! $page instanceof CategoryPage) {
            return $page;
        }

        $around = $this->visit->around($store, $locale, $page->categoryId);
        // Marked chosen: what the page applied - the choices it still offers (P14).
        $filters = $page->chosen ?? $filters->forViewer($viewer);

        return new ShopCategoryPage(
            $page->name,
            $page->slug,
            $around['path'],
            $around['children'],
            $this->cards($store, $locale, $page->products->cards),
            $page->products->next,
            self::filters($page->filters ?? new CategoryFilters([], [], []), $filters, $page->ratingSort),
            self::address('storefront.catalog.category', $locale, ['slug' => $page->slug], $page->otherSlug === null ? null : ['slug' => $page->otherSlug], false),
            $this->facts($store, $page->products->cards),
        );
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public function brand(StoreId $store, string $locale, string $slug, ?string $after): ShopBrandPage|Moved|null
    {
        $page = $this->catalog->brand($store, $locale, $this->visit->viewer(), $slug, $after);

        if ($page === null || $page instanceof Moved) {
            return $page;
        }

        $logo = $page->logoMediaId === null ? null : ($this->platform->mediaUrlsOf([$page->logoMediaId])[strtolower($page->logoMediaId)] ?? null);

        return new ShopBrandPage(
            $page->name,
            $page->slug,
            $logo === null || $logo->variants === [] ? null : $logo->variants,
            $page->description,
            $this->cards($store, $locale, $page->products->cards),
            $page->products->next,
            self::address('storefront.catalog.brand', $locale, ['slug' => $page->slug], $page->otherSlug === null ? null : ['slug' => $page->otherSlug], false),
            $this->facts($store, $page->products->cards),
        );
    }

    /**
     * The product's page as props, its own address added to what the cache keeps — and the other
     * modules' sections (amendment 26(b)), read on every request, never kept: they change with what
     * those modules decide and with who is looking. A page not available now carries none.
     *
     * @param  array<string, string>  $after  section key => where it goes on from ("Show more")
     * @param  array<string, string>  $filters  section key => what it is narrowed to (amendment 31)
     * @return array<string, mixed>|Moved|null
     *
     * @throws InvalidCatalogAttribute
     */
    public function product(StoreId $store, string $locale, string $slug, array $after = [], array $filters = []): array|Moved|null
    {
        $owner = $this->catalog->productOwner($locale, $slug);

        if ($owner === null) {
            return null;
        }

        $viewer = $this->visit->viewer();
        /** @var array{page: array<string, mixed>|null, other: string|null, category: string|null, companies?: bool|null} $kept */
        $kept = $this->cache->remember("product:{$store->value}:{$locale}:{$viewer->value}:{$owner['id']}", function () use ($store, $locale, $viewer, $owner): array {
            $page = $this->catalog->productPage($store, $locale, $viewer, $owner['id'], $owner['slug']);

            if ($page === null) {
                return ['page' => null, 'other' => null, 'category' => null, 'companies' => null];
            }

            $relations = $page->available ? $this->catalog->relations($store, $locale, $viewer, $owner['id']) : null;
            $price = $this->money($store, $locale);

            return ['page' => (new ShopProductPage(
                $page->productId,
                $page->name,
                $page->slug,
                $page->available,
                $page->description,
                $page->photos,
                self::labels($page->labels),
                // Drawn from this viewer's menu when the page is shown, never kept.
                [],
                $page->brand === null ? null : new ShopLinkData($page->brand->name, $page->brand->slug),
                array_map(static fn (ShopChoice $choice): ShopChoiceData => new ShopChoiceData(
                    $choice->attributeId,
                    $choice->name,
                    $choice->isColour,
                    array_map(static fn (ShopChoiceValue $value): ShopChoiceValueData => new ShopChoiceValueData($value->valueId, $value->name, $value->swatch, $value->onSale), $choice->values),
                ), $page->choices),
                array_map(fn (ShopVariant $variant): ShopVariantData => self::variant($variant, $price), $page->variants),
                $page->warranty === null ? null : new ShopWarrantyData($page->warranty->name, $page->warranty->periodMonths, $page->warranty->terms),
                $page->selling === null ? null : new ShopSellingData($page->selling->retailMinimum, $page->selling->retailMaximum, $page->selling->wholesaleMinimum, $page->selling->wholesaleMaximum),
                $this->cards($store, $locale, $relations === null ? [] : $relations->goesWith),
                $this->cards($store, $locale, $relations === null ? [] : $relations->mayAlsoLike),
                new ShopAddressData('', null, null, true),
            ))->toArray(), 'other' => $page->otherSlug, 'category' => $page->categoryId,
                // A guest's: whether companies may buy it here, so whether its wholesale is shown (amendment 35).
                // Kept with the page: a change of who sees its category is a Catalog change, a new version.
                'companies' => $viewer === ShopperKind::Guest ? $this->openToCompanies($store, $owner['id']) : null];
        });

        if ($kept['page'] === null) {
            return null;
        }

        if ($owner['moved']) {
            return new Moved($owner['slug']);
        }

        // Not available now: kept out of search engines (§1.4, P18).
        $address = self::address('storefront.catalog.product', $locale, ['slug' => $owner['slug']], $kept['other'] === null ? null : ['slug' => $kept['other']], $kept['page']['available'] !== true);

        // The path to its category as this viewer's menu has it: no step a page they cannot open.
        $around = $kept['category'] === null ? null : $this->visit->around($store, $locale, $kept['category']);
        $path = $around === null || $around['self'] === null ? [] : [...$around['path'], $around['self']];

        $customerId = $this->customerId();
        // Not available now: only the sections read on such a page too - the Save heart (amendment 34).
        // The page's sizes this viewer can buy wholesale, as it read them (amendment 35): none for an individual,
        // and a guest's only where companies may buy the product - as its card and Sales's Add to Cart have it.
        $wholesaleViewer = match ($viewer) {
            ShopperKind::Individual => false,
            ShopperKind::Company => true,
            ShopperKind::Guest => $kept['companies'] ?? $this->openToCompanies($store, $owner['id']),
        };
        $wholesaleSizes = ! $wholesaleViewer ? [] : array_values(array_map(
            static fn (array $variant): string => (string) $variant['id'],
            array_filter(self::listOf($kept['page']['variants'] ?? []), static fn (array $variant): bool => ($variant['sellsWholesale'] ?? false) === true),
        ));
        // Every size the page shows, in its order (amendment 36).
        $sizes = array_map(static fn (array $variant): string => (string) $variant['id'], self::listOf($kept['page']['variants'] ?? []));
        $sections = $this->sections->read($store, $locale === 'ar' ? 'ar' : 'en', $owner['id'], $customerId, $after, $filters, $kept['page']['available'] === true, $viewer, $wholesaleSizes, $sizes);
        // The relations' cards are kept with the page; what the modules add to them is this viewer's (amendment 32).
        $related = array_column([...self::listOf($kept['page']['goesWith'] ?? []), ...self::listOf($kept['page']['mayAlsoLike'] ?? [])], 'productId');

        return [
            ...$kept['page'],
            'path' => array_map(static fn (ShopLinkData $link): array => $link->toArray(), $path),
            'address' => $address->toArray(),
            'sections' => (object) $sections,
            // The same shape as the lists' `cardFacts`: [] when empty.
            'cardFacts' => $this->cardFacts->read($store, $customerId, array_map('strval', $related)),
        ];
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public function search(StoreId $store, string $locale, string $query, ?string $after): ShopSearchPage
    {
        $found = $this->search->results($store, $locale, $this->visit->viewer(), $query, ShopSearch::RESULTS, $after);

        // A results page is its search's, not a page of the shop's own: kept out of search engines.
        return new ShopSearchPage($query, $found->total, $this->cards($store, $locale, $found->cards), $found->next, self::address('storefront.catalog.search', $locale, ['q' => $query], ['q' => $query], true), $this->facts($store, $found->cards));
    }

    /**
     * The store's best sellers as this viewer's cards, with what the modules add to them (amendment 36).
     *
     * @return array{cards: list<array<string, mixed>>, cardFacts: array<string, array<string, array<string, mixed>>>}
     *
     * @throws InvalidCatalogAttribute
     */
    public function bestSelling(StoreId $store, string $locale, int $limit): array
    {
        $cards = $this->catalog->bestSelling($store, $locale, $this->visit->viewer(), $limit);

        return [
            'cards' => array_map(static fn (ShopCardData $card): array => $card->toArray(), $this->cards($store, $locale, $cards)),
            'cardFacts' => $this->facts($store, $cards),
        ];
    }

    /**
     * @return list<ShopCardData>
     *
     * @throws InvalidCatalogAttribute
     */
    public function suggest(StoreId $store, string $locale, string $typed): array
    {
        return $this->cards($store, $locale, $this->search->suggest($store, $locale, $this->visit->viewer(), $typed));
    }

    /**
     * @param  array<string, string>  $here
     * @param  array<string, string>|null  $there  the same page's parameters in the other language; none when it has no address there
     */
    private static function address(string $route, string $locale, array $here, ?array $there, bool $noindex): ShopAddressData
    {
        $other = $locale === 'ar' ? 'en' : 'ar';

        return new ShopAddressData(
            route($route, $here),
            $there === null ? null : route($route, ['locale' => $other, ...$there]),
            $there === null ? null : $other,
            $noindex,
        );
    }

    private static function filters(CategoryFilters $filters, ShopFilters $chosen, bool $ratingSort): ShopFiltersData
    {
        return new ShopFiltersData(
            self::choices($filters->brands, $chosen->brands),
            array_map(static fn (FilterGroup $group): ShopFilterGroupData => new ShopFilterGroupData($group->attributeId, $group->name, self::choices($group->choices, $chosen->values)), $filters->attributes),
            self::choices($filters->sides, $chosen->side === null ? [] : [$chosen->side]),
            $chosen->sort,
            $ratingSort,
        );
    }

    /**
     * @param  list<FilterChoice>  $choices
     * @param  list<string>  $picked
     * @return list<ShopFilterChoiceData>
     */
    private static function choices(array $choices, array $picked): array
    {
        return array_map(
            static fn (FilterChoice $choice): ShopFilterChoiceData => new ShopFilterChoiceData($choice->id, $choice->name, $choice->swatch, $choice->count, in_array($choice->id, $picked, true)),
            $choices,
        );
    }

    /**
     * @param  Closure(?int): ?MoneyData  $price
     */
    private static function variant(ShopVariant $variant, Closure $price): ShopVariantData
    {
        $values = [];

        foreach ($variant->values as $value) {
            $values[$value->attributeId] = $value->valueId;
        }

        return new ShopVariantData(
            $variant->variantId,
            $values,
            $variant->sellsRetail,
            $variant->sellsWholesale,
            $variant->lastPieces,
            $variant->photos,
            array_map(static fn (VariantDetail $detail): ShopDetailData => new ShopDetailData($detail->name, $detail->value), $variant->details),
            $variant->weightGrams,
            $variant->lengthMm,
            $variant->widthMm,
            $variant->heightMm,
            $price($variant->priceMinor),
            $price($variant->priceBeforeMinor),
        );
    }

    /** The signed-in customer looking, if any. */
    private function customerId(): ?string
    {
        $actor = $this->actors->current();

        return $actor->type === ActorType::Customer && $actor->id !== null ? strtolower($actor->id) : null;
    }

    /** Whether companies may buy the product in the store: its category open to them (amendment 14). */
    private function openToCompanies(StoreId $store, string $productId): bool
    {
        return $this->reads->productsOpenTo($store->value, ShopperKind::Company, [$productId]) !== [];
    }

    /**
     * What the modules add to these cards for this viewer (amendment 32).
     *
     * @param  list<ProductCard>  $cards
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function facts(StoreId $store, array $cards): array
    {
        return $this->cardFacts->read($store, $this->customerId(), array_map(static fn (ProductCard $card): string => $card->productId, $cards));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $cards): array
    {
        return is_array($cards) ? array_values(array_filter($cards, 'is_array')) : [];
    }

    /**
     * @param  list<ProductCard>  $cards
     * @return list<ShopCardData>
     */
    private function cards(StoreId $store, string $locale, array $cards): array
    {
        $price = $cards === [] ? static fn (?int $minor): ?MoneyData => null : $this->money($store, $locale);

        return array_map(static fn (ProductCard $card): ShopCardData => new ShopCardData(
            $card->productId, $card->name, $card->slug, $card->photo, self::labels($card->labels), $card->lastPieces, $card->rating?->average, $card->rating->count ?? 0,
            $card->price === null ? null : new ShopCardPriceData($price($card->price->now), $price($card->price->before), $price($card->price->from), $price($card->price->wholesaleFrom)),
        ), $cards);
    }

    /**
     * An amount as the screens show it (amendment 35): written by the server in the store's currency
     * (`MoneyData`, pricing.md §4.3.1) — the prices Pricing pushed are in it.
     *
     * @return Closure(?int): ?MoneyData
     */
    private function money(StoreId $store, string $locale): Closure
    {
        $found = $this->platform->store($store);

        return static fn (?int $minor): ?MoneyData => $minor === null || $found === null ? null : MoneyData::of(Money::of($minor, $found->currencyCode), $found, $locale);
    }

    /**
     * @param  list<CardLabel>  $labels
     * @return list<ShopLabelData>
     */
    private static function labels(array $labels): array
    {
        return array_map(static fn (CardLabel $label): ShopLabelData => new ShopLabelData($label->name, $label->tone), $labels);
    }
}
