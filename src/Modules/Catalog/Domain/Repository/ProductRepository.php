<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\Product;

/**
 * Products, their slugs and the codes their variants ever held (catalog.md §1.1, §1.2, §5.1),
 * changed under `ListLocks::PRODUCTS`.
 */
interface ProductRepository
{
    public function nextId(): string;

    public function find(string $productId): ?Product;

    /** Read again, its row locked, inside a change. */
    public function byId(string $productId): ?Product;

    public function slugTaken(string $locale, string $slug, ?string $exceptProductId = null): bool;

    /** Inserts the product and records its slugs as current. */
    public function add(Product $product): void;

    /** Writes the product; a slug that changed joins its history and the old one stays held. */
    public function update(Product $product): void;

    /** Removes a draft with every row of its own: slugs, codes, variants (§4.1). */
    public function delete(string $productId): void;

    /** The product holding this code, now or once — null when none does (amendment 3(e)). */
    public function codeHolder(string $code): ?string;

    /** Makes the code one of the product's; a code it holds already stays as it is. */
    public function holdCode(string $productId, string $code): void;

    /** Lets a draft's code go, free for any product again (amendment 3(c)). */
    public function releaseCode(string $productId, string $code): void;

    /**
     * @return list<string> every code the product holds, current or given up
     */
    public function codesOf(string $productId): array;

    public function anyInCategory(string $categoryId): bool;

    public function anyWithBrand(string $brandId): bool;

    public function anyWithWarranty(string $warrantyId): bool;

    public function anyWithAttributeSet(string $setId): bool;

    /** Whether any variant is built on the set — of a product taking it (amendment 3(k)). */
    public function variantsOnSet(string $setId): bool;

    /**
     * @return list<string> the gallery's media ids, in order
     */
    public function gallery(string $productId): array;

    /**
     * @param  list<string>  $mediaIds  in order
     */
    public function replaceGallery(string $productId, array $mediaIds): void;

    /**
     * @return list<string> the products whose gallery holds the media
     */
    public function withPhoto(string $mediaId): array;

    public function removePhoto(string $productId, string $mediaId): void;

    /**
     * @return list<array{word: string, normalized: string}> in order
     */
    public function searchWords(string $productId): array;

    /**
     * @param  list<array{word: string, normalized: string}>  $words  in order
     */
    public function replaceSearchWords(string $productId, array $words): void;

    /**
     * @return array<string, string> value id => its attribute's id, in the order they were set
     */
    public function filterValues(string $productId): array;

    /**
     * @param  array<string, string>  $values  value id => its attribute's id
     */
    public function replaceFilterValues(string $productId, array $values): void;

    public function anyWithFilterValue(string $valueId): bool;

    public function anyWithFilterAttribute(string $attributeId): bool;

    /**
     * @return list<string> the related products of this kind, in order
     */
    public function relations(string $productId, string $kind): array;

    /**
     * @param  list<string>  $relatedIds  in order
     */
    public function replaceRelations(string $productId, string $kind, array $relatedIds): void;
}
