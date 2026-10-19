<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use Shared\Domain\ValueObject\Money;

/**
 * One coupon in full, for its page and its editor (promotions.md §1.1, §1.2, §3): the coupon as stored,
 * its state now, its lists with their names, and how many customers it is assigned to.
 */
final readonly class CouponView
{
    /**
     * @param  list<RuleView>  $rules
     */
    public function __construct(
        public CouponRow $coupon,
        public ?Money $cap,
        public ?Money $minimum,
        public ?int $maxUsesPerCustomer,
        public bool $allowWithPoints,
        public bool $onReducedLines,
        public bool $forRetail,
        public bool $forWholesale,
        public bool $forPublic,
        public bool $forCompany,
        public array $rules,
        public int $assignedCount,
    ) {}
}
