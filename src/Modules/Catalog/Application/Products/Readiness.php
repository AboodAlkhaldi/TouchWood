<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\ProductNotReady;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVariantsStatus;

/**
 * What a product needs to be shown (catalog.md §1.1): its English name and so its English slug
 * (amendment 3(g)), the description in both languages, an active category with no sub-categories, at
 * least one variant not archived — each with its code — and at least one gallery photo whose sizes
 * are ready. **A ready product keeps every rule** (§9.3 #7): a change that would take one away is
 * refused, naming what it would leave missing — except that **a category deactivated after it was
 * placed there stays** (amendment 3(m)): it was active and the lowest when chosen, and a category
 * holding products takes no sub-category, so only its being switched off changed.
 *
 * An archived product may be edited, so it can be made whole; it is not made ready, deleted, nor are
 * its variants deleted until it is restored (§4.1, amendment 3(m)).
 */
final readonly class Readiness
{
    public function __construct(
        private VariantRepository $variants,
        private CategoryRepository $categories,
        private ReadyPhotos $readyPhotos,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws ProductArchived
     */
    public function requireNotArchived(Product $product): void
    {
        if ($product->stage() === ProductStage::Archived) {
            throw new ProductArchived;
        }
    }

    /**
     * What the product lacks to be made ready or restored ready, as it would be — with this gallery or
     * these variants instead of its own, when a change is about them.
     *
     * @param  list<string>|null  $gallery  media ids
     * @param  list<Variant>|null  $variants
     * @return list<string> the rules it does not meet: `name_en`, `description_ar`, `description_en`,
     *                      `category`, `variants`, `photos`
     */
    public function missing(Product $product, ?array $gallery = null, ?array $variants = null): array
    {
        return $this->lacking($product, $gallery, $variants, keeping: false);
    }

    /**
     * For a ready product, after a change: every rule still met, a category deactivated since it was
     * placed there kept.
     *
     * @param  list<string>|null  $gallery
     * @param  list<Variant>|null  $variants
     *
     * @throws ProductNotReady
     */
    public function requireKept(Product $product, ?array $gallery = null, ?array $variants = null): void
    {
        if ($product->stage() !== ProductStage::Ready) {
            return;
        }

        $missing = $this->lacking($product, $gallery, $variants, keeping: true);

        if ($missing !== []) {
            throw new ProductNotReady($missing);
        }
    }

    /**
     * @param  list<string>|null  $gallery
     * @param  list<Variant>|null  $variants
     * @param  bool  $keeping  a ready product staying ready: its category need only be there
     * @return list<string>
     */
    private function lacking(Product $product, ?array $gallery, ?array $variants, bool $keeping): array
    {
        $missing = [];

        if ($product->name()->en === null || $product->slugs()->en === null) {
            $missing[] = 'name_en';
        }

        if ($product->descriptionAr() === null) {
            $missing[] = 'description_ar';
        }

        if ($product->descriptionEn() === null) {
            $missing[] = 'description_en';
        }

        if ($keeping ? $product->categoryId() === null : ! $this->categoryShowable($product->categoryId())) {
            $missing[] = 'category';
        }

        $variants ??= $this->variants->ofProduct($product->id());

        if (array_filter($variants, static fn (Variant $variant): bool => ! $variant->isArchived()) === []) {
            $missing[] = 'variants';
        }

        $readyPhotos = $gallery === null
            ? $this->readyPhotos->of($product->id())
            : array_filter($gallery, fn (string $mediaId): bool => $this->platform->media($mediaId)?->variantsStatus === MediaVariantsStatus::Ready);

        if ($readyPhotos === []) {
            $missing[] = 'photos';
        }

        return $missing;
    }

    private function categoryShowable(?string $categoryId): bool
    {
        if ($categoryId === null) {
            return false;
        }

        $category = $this->categories->find($categoryId);

        return $category !== null && $category->isActive() && $this->categories->childrenOf($category->id()) === [];
    }
}
