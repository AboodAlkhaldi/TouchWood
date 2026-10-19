<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Controller;

use RuntimeException;

/**
 * A coupon form's value the screen could not read - an amount with too many decimals, a number with
 * letters, a moment that is not one - said beside its own box before the use case sees anything
 * (promotions.md §4.1 M2). `rule` names the words: promotions::admin_coupons.problem.{rule}.
 */
final class CouponFormProblem extends RuntimeException
{
    /**
     * @param  array<string, int|string>  $values
     */
    public function __construct(
        public readonly string $field,
        public readonly string $rule,
        public readonly array $values = [],
    ) {
        parent::__construct("{$field}: {$rule}");
    }
}
