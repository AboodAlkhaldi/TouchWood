<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListCoupons;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\CouponState;

/** A store's coupons, newest first (promotions.md §3, "Marketing › Coupons"). */
final readonly class ListCoupons
{
    public function __construct(
        public string $storeId,
        public ?CouponState $state = null,
        public ?string $codeStart = null,
        public ?DateTimeImmutable $beforeAt = null,
        public ?string $beforeId = null,
        public int $perPage = 50,
    ) {}
}
