<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Application\Lists\CatalogImages;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Enums\AttributeKind;
use Modules\Catalog\Public\Enums\ProductStage;

/**
 * What hangs off a product, as a form sends it (catalog.md §1.1, §1.10, amendment 3) — photos,
 * filter values, related products — read against the rows it names inside the product's change.
 * What the product already has may stay after it was deactivated or archived; what it newly takes
 * must be usable now.
 */
final readonly class ProductParts
{
    /** More filter values than any product needs, and a ceiling on what one request walks through. */
    public const int MAX_FILTER_VALUES = 100;

    /** Related products, and products that go with it: each list (amendment 3(d)). */
    public const int MAX_RELATIONS = 20;

    public function __construct(
        private CatalogImages $images,
        private AttributeRepository $attributes,
        private ProductRepository $products,
    ) {}

    /**
     * Photos, in order: each a public image of the media library, each once.
     *
     * @param  array<array-key, mixed>  $mediaIds  as the request sent them
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute|TooMany
     */
    public function photos(string $attribute, array $mediaIds, int $max): array
    {
        if (count($mediaIds) > $max) {
            throw new TooMany($attribute, $max);
        }

        $kept = [];

        foreach ($mediaIds as $mediaId) {
            $id = is_string($mediaId) ? $this->images->check($attribute, $mediaId) : null;

            if ($id === null) {
                throw new InvalidCatalogAttribute($attribute, 'public images of the media library');
            }

            if (in_array($id, $kept, true)) {
                throw new InvalidCatalogAttribute($attribute, 'each photo once');
            }

            $kept[] = $id;
        }

        return $kept;
    }

    /**
     * Filter values (amendment 3(a)): values of filter attributes, several of one attribute allowed,
     * each once; one newly taken must be active, its attribute too.
     *
     * @param  array<array-key, mixed>  $valueIds  as the request sent them
     * @param  array<string, string>  $held  value id => attribute id, what the product has now
     * @return array<string, string> value id => its attribute's id
     *
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|TooMany
     */
    public function filterValues(array $valueIds, array $held): array
    {
        if (count($valueIds) > self::MAX_FILTER_VALUES) {
            throw new TooMany('filter_values', self::MAX_FILTER_VALUES);
        }

        $kept = [];

        foreach ($valueIds as $valueId) {
            if (! is_string($valueId)) {
                throw new InvalidCatalogAttribute('filter_values', 'values, by their ids');
            }

            $found = $this->attributes->findValue($valueId) ?? throw new ListItemNotFound($valueId);
            // The attribute's row before the value's, the order every change locking both takes.
            $attribute = $this->attributes->byId($found->attributeId()) ?? throw new ListItemNotFound($found->attributeId());
            $value = $this->attributes->valueById($valueId) ?? throw new ListItemNotFound($valueId);

            if ($attribute->kind() !== AttributeKind::Filterable) {
                throw new InvalidCatalogAttribute('filter_values', 'values of filter attributes');
            }

            if (isset($kept[$value->id()])) {
                throw new InvalidCatalogAttribute('filter_values', 'each value once');
            }

            if (! isset($held[$value->id()]) && (! $value->isActive() || ! $attribute->isActive())) {
                throw new ListItemInactive;
            }

            $kept[$value->id()] = $attribute->id();
        }

        return $kept;
    }

    /**
     * Related products of one kind, in order (§1.10, amendment 3(d)): at most 20, each once, never the
     * product itself; one newly picked must be ready — one picked before may stay after it was
     * archived, shown nowhere.
     *
     * @param  array<array-key, mixed>  $productIds  as the request sent them
     * @param  list<string>  $held  what the product has of this kind now
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute|ProductNotFound|TooMany
     */
    public function relations(string $productId, array $productIds, array $held): array
    {
        if (count($productIds) > self::MAX_RELATIONS) {
            throw new TooMany('relations', self::MAX_RELATIONS);
        }

        $kept = [];

        foreach ($productIds as $relatedId) {
            if (! is_string($relatedId)) {
                throw new InvalidCatalogAttribute('relations', 'products, by their ids');
            }

            $related = $this->products->byId($relatedId) ?? throw new ProductNotFound($relatedId);

            if ($related->id() === $productId) {
                throw new InvalidCatalogAttribute('relations', 'products other than this one');
            }

            if (in_array($related->id(), $kept, true)) {
                throw new InvalidCatalogAttribute('relations', 'each product once');
            }

            if ($related->stage() !== ProductStage::Ready && ! in_array($related->id(), $held, true)) {
                throw new InvalidCatalogAttribute('relations', 'ready products');
            }

            $kept[] = $related->id();
        }

        return $kept;
    }
}
