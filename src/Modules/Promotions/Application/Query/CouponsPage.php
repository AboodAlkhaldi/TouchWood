<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;

/** A page of a store's coupons, newest first, with the cursor to the next (null at the end). */
final readonly class CouponsPage
{
    /**
     * @param  list<CouponRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?DateTimeImmutable $nextAt,
        public ?string $nextId,
    ) {}
}
