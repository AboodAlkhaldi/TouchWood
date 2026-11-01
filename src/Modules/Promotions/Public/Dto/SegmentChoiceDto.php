<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

/** One segment of a store, for Ops' notice form and message composer (`PromotionsApi::storeSegments`, `storeSegment`). */
final readonly class SegmentChoiceDto
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        /** How many customers it holds now. */
        public int $memberCount,
    ) {}
}
