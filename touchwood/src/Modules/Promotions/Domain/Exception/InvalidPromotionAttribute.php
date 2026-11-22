<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value the domain refuses (promotions.md §7): a code's characters or length, a percentage, an
 * amount in another currency, dates in the wrong order, a product that is not ready, an inactive
 * category or brand, an archived gift variant, a rule's numbers, a combination that can never apply, a
 * store that is not the coupon's or the order's, a caller's permission that is not its own module's.
 */
final class InvalidPromotionAttribute extends PromotionsError
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'promotions.invalid_promotion_attribute';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
