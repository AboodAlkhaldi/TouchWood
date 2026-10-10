<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category on the categories screen (catalog.md §1.5, §4.4 S2): its products in it and under it,
 * and its place in the chosen store's menu — the store's own, or the base store's that stands for it
 * until the store's admins place it (amendment 5(a)).
 */
#[TypeScript]
final class CategoryData extends Data
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $nameAr,
        public string $nameEn,
        public ?string $slugAr,
        public ?string $slugEn,
        public bool $active,
        public bool $deactivatedWithParent,
        public ?string $imageMediaId,
        public ?string $image,
        /** In it itself — a category holding products takes no sub-category. */
        public int $productsHere,
        /** In it and in every category under it. */
        public int $products,
        public ?int $storeRank,
        public ?int $baseRank,
    ) {}
}
