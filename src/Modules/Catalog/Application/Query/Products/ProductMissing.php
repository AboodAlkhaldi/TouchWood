<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

use Modules\Platform\Public\Enums\MediaVariantsStatus;

/**
 * What a product lacks to be made ready, from what the screens read of it (`ProductCore`, its
 * gallery's photo states) - `Readiness::missing()`'s rules, read nothing per rule: the English name
 * and address, both descriptions, a category that may hold it, a variant not archived, a ready photo.
 * A product's page and a store file's page (§4.4 S9, S10) ask it alike; tests/Modules/Catalog/
 * Integration/CatalogProductReadsTest holds it to `Readiness`'s answers.
 */
final class ProductMissing
{
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
