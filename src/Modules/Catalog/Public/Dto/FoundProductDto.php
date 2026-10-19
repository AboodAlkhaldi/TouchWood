<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Catalog\Public\Enums\ProductStage;

/**
 * **A product as a picker shows it** (catalog.md amendment 23(b), (c)): its names, its first photo and
 * every code its sizes carry.
 */
final readonly class FoundProductDto
{
    /**
     * @param  list<string>  $codes  in order
     */
    public function __construct(
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public ?string $photoMediaId,
        public array $codes,
        public ProductStage $stage,
    ) {}
}
