<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

/**
 * A product a campaign reaches, with its sizes as the preview judges them (pricing.md §9.6 #8).
 */
final readonly class CampaignPreviewProductDto
{
    /**
     * @param  list<CampaignPreviewSizeDto>  $sizes
     */
    public function __construct(
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public array $sizes,
    ) {}
}
