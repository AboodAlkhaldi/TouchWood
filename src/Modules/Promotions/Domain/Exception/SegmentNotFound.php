<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown segment, or one in a store the staff member does not cover — the same answer for both
 * (promotions.md §7).
 */
final class SegmentNotFound extends PromotionsError
{
    public function __construct()
    {
        parent::__construct('No such segment.');
    }

    public function type(): string
    {
        return 'promotions.segment_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
