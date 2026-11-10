<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;

/** A page of a store's coupons, newest first, with the cursor to the next (null at the end). */
final readonly class CouponsPage
{
    /**
     * @param  list<CouponRow>  $rows
     * @param  array<string, int>  $counts  each state's coupons for the same code start, every state named, when asked; else none
     */
    public function __construct(
        public array $rows,
        public ?DateTimeImmutable $nextAt,
        public ?string $nextId,
        public array $counts = [],
    ) {}
}
