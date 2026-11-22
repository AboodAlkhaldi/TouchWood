<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVariantsStatus;

/**
 * What a product lacks to be made ready, from what the screens read of it (`ProductCore`, its
 * gallery's photo states) - `Readiness::missing()`'s rules, read nothing per rule: the English name
 * and address, both descriptions, a category that may hold it, a variant not archived, a ready photo.
 * A product's page, a store file's and an import's (§4.4 S9–S11) ask it alike; tests/Modules/Catalog/
 * Integration/CatalogProductReadsTest holds it to `Readiness`'s answers.
 */
final class ProductMissing
{
    /**
     * What each draft among these products lacks, every draft's gallery photos read together in one
     * Platform read - a page of many products reads nothing per product (P13).
     *
     * @param  array<string, ProductCore>  $products
     * @return array<string, list<string>> by product id, for the drafts only
     */
    public static function ofDrafts(PlatformApi $platform, array $products): array
    {
        $drafts = array_filter($products, static fn (ProductCore $product): bool => $product->stage === ProductStage::Draft->value);
        $mediaIds = [];

        foreach ($drafts as $draft) {
            $mediaIds = [...$mediaIds, ...$draft->gallery];
        }

        $states = [];

        if ($mediaIds !== []) {
            foreach ($platform->mediaOf(array_values(array_unique($mediaIds))) as $mediaId => $media) {
                $states[$mediaId] = $media->variantsStatus->value ?? MediaVariantsStatus::Pending->value;
            }
        }

        $missing = [];

        foreach ($drafts as $id => $draft) {
            $missing[$id] = self::of($draft, array_intersect_key($states, array_flip($draft->gallery)));
        }

        return $missing;
    }

    /**
     * @param  array<string, string>  $photoStates  the gallery's photos' states
     * @return list<string>
     */
    public static function of(ProductCore $product, array $photoStates): array
    {
        $missing = [];

        if ($product->nameEn === null || $product->slugEn === null) {
            $missing[] = 'name_en';
        }

        if ($product->descriptionAr === null) {
            $missing[] = 'description_ar';
        }

        if ($product->descriptionEn === null) {
            $missing[] = 'description_en';
        }

        if (! $product->categoryShowable) {
            $missing[] = 'category';
        }

        if (! $product->hasVariant) {
            $missing[] = 'variants';
        }

        if (! in_array(MediaVariantsStatus::Ready->value, $photoStates, true)) {
            $missing[] = 'photos';
        }

        return $missing;
    }
}
