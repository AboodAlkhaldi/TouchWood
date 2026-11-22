<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown gift level, or one in a store the staff member does not cover — the same answer for both
 * (promotions.md §7).
 */
final class GiftLevelNotFound extends PromotionsError
{
    public function __construct()
    {
        parent::__construct('No such gift level.');
    }

    public function type(): string
    {
        return 'promotions.gift_level_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
