<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product a deactivation reaches (catalog.md §1.5, §1.6), for the fates dialog: its name in both
 * languages, its stage, and the category it sits in.
 */
#[TypeScript]
final class ReachedProductData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        /** DRAFT, READY or ARCHIVED. */
        public string $stage,
        public ?string $categoryId,
    ) {}
}
