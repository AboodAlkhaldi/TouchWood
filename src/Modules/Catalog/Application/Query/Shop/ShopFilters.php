<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Platform\Public\Enums\ShopperKind;

/**
 * What a shopper chose on a category's page (catalog.md §4.5, P14, P15): brands, the filter
 * attributes' values, Retail or Wholesale, and the sort. Several brands, or several values of one
 * attribute, show any of them; values of two attributes, both. Sent back by the browser in the
 * address, so read as strictly as any other input; an id that names nothing finds nothing.
 */
final readonly class ShopFilters
{
    /** The default: best-selling first — newest until Sales ranks products (P15). */
    public const string BEST_SELLING = 'best-selling';

    public const string NEWEST = 'newest';

    public const array SORTS = [self::BEST_SELLING, self::NEWEST];

    public const string RETAIL = 'retail';

    public const string WHOLESALE = 'wholesale';

    public const array SIDES = [self::RETAIL, self::WHOLESALE];

    /** Values a shopper may pick at once. */
    public const int VALUES_MAX = 50;

    /**
     * @param  list<string>  $brands  ids, in small letters
     * @param  list<string>  $values  attribute value ids, in small letters
     */
    private function __construct(
        public array $brands,
        public array $values,
        public ?string $side,
        public string $sort,
    ) {}

    public static function none(): self
    {
        return new self([], [], null, self::BEST_SELLING);
    }

    /**
     * @param  list<string>  $brands
     * @param  list<string>  $values
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(array $brands = [], array $values = [], ?string $side = null, ?string $sort = null): self
    {
        // Counted as sent: an address naming more than a page offers is refused, not trimmed.
        if (count($brands) > ShopCatalog::BRANDS_MAX) {
            throw new InvalidCatalogAttribute('brands', 'at most '.ShopCatalog::BRANDS_MAX.' brands');
        }

        if (count($values) > self::VALUES_MAX) {
            throw new InvalidCatalogAttribute('values', 'at most '.self::VALUES_MAX.' values');
        }

        $brands = self::ids($brands);
        $values = self::ids($values);

        if ($side !== null && ! in_array($side, self::SIDES, true)) {
            throw new InvalidCatalogAttribute('side', 'retail or wholesale');
        }

        if ($sort !== null && ! in_array($sort, self::SORTS, true)) {
            throw new InvalidCatalogAttribute('sort', implode(' or ', self::SORTS));
        }

        return new self($brands, $values, $side, $sort ?? self::BEST_SELLING);
    }

    /** An individual buys retail only (amendment 14(e)): Retail or Wholesale is not theirs to pick. */
    public function forViewer(ShopperKind $viewer): self
    {
        return $viewer === ShopperKind::Individual ? new self($this->brands, $this->values, null, $this->sort) : $this;
    }

    /**
     * Only the choices the page offers (catalog.md §4.5): a brand not listed here, a value switched off
     * or held by nothing here, a side nothing here is sold on — each counts as not made, in the cards
     * as in the counts, so what the shopper sees chosen is what the page shows.
     */
    public function narrowedTo(CategoryFilters $offered): self
    {
        $ids = static fn (array $choices): array => array_map(static fn (FilterChoice $choice): string => $choice->id, $choices);
        $values = [];

        foreach ($offered->attributes as $group) {
            array_push($values, ...$ids($group->choices));
        }

        return new self(
            array_values(array_intersect($this->brands, $ids($offered->brands))),
            array_values(array_intersect($this->values, $values)),
            in_array($this->side, $ids($offered->sides), true) ? $this->side : null,
            $this->sort,
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $ids)));
    }
}
