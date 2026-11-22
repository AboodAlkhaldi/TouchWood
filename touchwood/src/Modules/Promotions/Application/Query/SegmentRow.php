<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\SegmentAudience;
use Modules\Promotions\Public\Enums\SegmentRule;
use Modules\Promotions\Public\Enums\SegmentType;

/**
 * One segment of a store's list (promotions.md §1.5, §3): its store (a lower-case id), its rule, how
 * many it holds, when last refreshed.
 */
final readonly class SegmentRow
{
    public function __construct(
        public string $id,
        public string $storeId,
        public string $nameAr,
        public string $nameEn,
        public SegmentType $type,
        public SegmentAudience $audience,
        public ?SegmentRule $rule,
        public ?int $ruleMonths,
        public ?int $ruleOrders,
        public int $memberCount,
        public ?DateTimeImmutable $refreshedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
