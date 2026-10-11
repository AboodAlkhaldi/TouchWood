<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value the domain refuses (loyalty.md §7): points that are not a positive whole number, a reason
 * empty or too long, returned amounts past the order's, a currency that is not the store's, a store
 * that is not the order's recorded store, a caller's permission that is not its own module's.
 */
final class InvalidPointsAttribute extends LoyaltyError
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'loyalty.invalid_points_attribute';
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
