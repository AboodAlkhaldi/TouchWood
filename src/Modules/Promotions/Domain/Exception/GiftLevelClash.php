<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Two active gift levels of one store with the same amount, for overlapping audiences and dates — one
 * amount, one gift (promotions.md §7, §1.7).
 */
final class GiftLevelClash extends PromotionsError
{
    public function __construct()
    {
        parent::__construct('Another active gift level has the same amount for the same buyers and dates.');
    }

    public function type(): string
    {
        return 'promotions.gift_level_clash';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
