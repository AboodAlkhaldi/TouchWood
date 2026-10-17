<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A new coupon's code is another coupon's in the store (promotions.md §7, §1.1). The property is not
 * named `code`: every exception already has one, the numeric error code.
 */
final class CodeTaken extends PromotionsError
{
    public function __construct(public readonly string $couponCode)
    {
        parent::__construct("The code {$couponCode} is already another coupon's in this store.");
    }

    public function type(): string
    {
        return 'promotions.code_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['code' => $this->couponCode];
    }
}
