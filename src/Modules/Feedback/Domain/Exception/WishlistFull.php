<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A 501st wishlist item (§1.4).
 */
final class WishlistFull extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('Your wishlist already holds 500 items.');
    }

    public function type(): string
    {
        return 'feedback.wishlist_full';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
