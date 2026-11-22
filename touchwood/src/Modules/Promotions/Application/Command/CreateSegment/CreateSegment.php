<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\CreateSegment;

final readonly class CreateSegment
{
    public function __construct(
        public string $storeId,
        public string $nameAr,
        public string $nameEn,
        public string $type,
        public string $audience,
        public ?string $rule = null,
        public ?int $ruleMonths = null,
        public ?int $ruleOrders = null,
    ) {}
}
