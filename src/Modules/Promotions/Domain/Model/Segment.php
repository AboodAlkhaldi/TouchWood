<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\SegmentAudience;
use Modules\Promotions\Public\Enums\SegmentRule;
use Modules\Promotions\Public\Enums\SegmentType;

/** A store's customer segment as it is now (promotions.md §1.5, §5). */
final readonly class Segment
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
        public ?DateTimeImmutable $refreshedAt,
    ) {}

    /**
     * What staff see changed (the audit log).
     *
     * @return array<string, int|string|null>
     */
    public function snapshot(): array
    {
        return [
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'type' => $this->type->value,
            'audience' => $this->audience->value,
            'rule' => $this->rule?->value,
            'rule_months' => $this->ruleMonths,
            'rule_orders' => $this->ruleOrders,
        ];
    }
}
