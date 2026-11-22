<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

/**
 * One line of a coupon's include or exclude list (promotions.md §1.2): a product, a category — which
 * reaches every category below it — or a brand.
 */
final readonly class CouponRule
{
    public function __construct(
        public RuleKind $kind,
        public RuleTarget $target,
        public string $targetId,
    ) {}
}
