<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeSegment;

/** A segment's names and rule; never its type or audience (owner, 2026-10-18, answer 23). */
final readonly class ChangeSegment
{
    public function __construct(
        public string $segmentId,
        public string $nameAr,
        public string $nameEn,
        public ?string $rule = null,
        public ?int $ruleMonths = null,
        public ?int $ruleOrders = null,
    ) {}
}
