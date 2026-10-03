<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Model\Category;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\Slugs;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * What adding, editing and moving a category share (catalog.md §1.5): its names and slugs, the
 * parent it goes under, and its place among its siblings, written into every store's order.
 */
final readonly class CategoryInput
{
    public function __construct(
        private CategoryRepository $categories,
        private PlatformApi $platform,
    ) {}

    /**
     * @return array{LocalizedName, Slugs}
     *
     * @throws InvalidCatalogAttribute
     */
    public function names(string $nameAr, string $nameEn, ?string $slugAr, ?string $slugEn): array
    {
        $name = LocalizedName::of($nameAr, $nameEn, Category::NAME_MAX);

        return [$name, Slugs::for($name, $slugAr, $slugEn)];
    }

    /**
     * @throws SlugTaken
     */
    public function requireFreeSlugs(Slugs $slugs, ?string $exceptCategoryId = null): void
    {
        foreach (['ar' => $slugs->ar->value, 'en' => $slugs->en->value] as $locale => $slug) {
            if ($this->categories->slugTaken($locale, $slug, $exceptCategoryId)) {
                throw new SlugTaken($slug);
            }
        }
    }

    /**
     * The category a new or moved one goes under, read under the categories' lock: it must exist
     * and be active. Null puts it at the top.
     *
     * @return string|null the parent's id
     *
     * @throws CategoryInactive|CategoryNotFound
     */
    public function parent(?string $parentId): ?string
    {
        if ($parentId === null || trim($parentId) === '') {
            return null;
        }

        $parent = $this->categories->byId($parentId) ?? throw new CategoryNotFound($parentId);

        if (! $parent->isActive()) {
            throw new CategoryInactive;
        }

        return $parent->id();
    }

    /**
     * Its place among its siblings, chosen by whoever adds or moves it, starting the same in every
     * store — on or off, so a store turned on later already has its order (amendment 1(d)).
     *
     * @throws InvalidCatalogAttribute
     */
    public function placeEverywhere(string $categoryId, int $rank): void
    {
        $this->categories->placeIn(
            $categoryId,
            array_map(static fn (StoreDto $store): string => $store->id, $this->platform->allStores()),
            ListPosition::check($rank, 'rank'),
        );
    }
}
