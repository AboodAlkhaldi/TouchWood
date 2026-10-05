<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\Brand;

interface BrandRepository
{
    public function nextId(): string;

    /** Read without a lock — for questions asked outside a change. */
    public function find(string $brandId): ?Brand;

    /** Read again, its row locked, inside a change. */
    public function byId(string $brandId): ?Brand;

    /** The default brand, its row locked; null only before the seed ran. */
    public function defaultBrand(): ?Brand;

    /**
     * Whether another brand holds the slug, or ever did (catalog.md §1.1): an old slug keeps
     * redirecting, so it is never given to another.
     */
    public function slugTaken(string $locale, string $slug, ?string $exceptBrandId = null): bool;

    /** Inserts the brand and records its two slugs as current. */
    public function add(Brand $brand): void;

    /** Writes the brand; a slug that changed joins its history and the old one stays held. */
    public function update(Brand $brand): void;

    public function delete(string $brandId): void;

    /**
     * @return list<Brand> every brand, by position and then name
     */
    public function all(): array;

    /**
     * @return array<int, string> every brand's fixed number (§1.6, amendment 7(b)) => its id
     */
    public function numbers(): array;

    /**
     * @return list<string> the brands whose logo this media is
     */
    public function withLogo(string $mediaId): array;
}
