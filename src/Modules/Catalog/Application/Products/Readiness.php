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
 * refused, naming what it would leave missing.
 *
 * Every product change also refuses an archived product, which only restoring changes (§7).
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
     * What the product lacks, as it would be — with this gallery or these variants instead of its
     * own, when a change is about them.
     *
     * @param  list<string>|null  $gallery  media ids
     * @param  list<Variant>|null  $variants
     * @return list<string> the rules it does not meet: `name_en`, `description_ar`, `description_en`,
     *                      `category`, `variants`, `photos`
     */
    public function missing(Product $product, ?array $gallery = null, ?array $variants = null): array
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

        if (! $this->categoryShowable($product->categoryId())) {
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

    /**
     * For a ready product, after a change: every rule still met.
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

        $missing = $this->missing($product, $gallery, $variants);

        if ($missing !== []) {
            throw new ProductNotReady($missing);
        }
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
