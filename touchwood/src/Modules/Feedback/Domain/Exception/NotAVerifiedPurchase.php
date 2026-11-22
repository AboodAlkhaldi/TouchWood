<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Writing a review with no delivered order of the product (§1.1).
 */
final class NotAVerifiedPurchase extends FeedbackError
{
    public function __construct()
    {
        parent::__construct('Only a customer who received this product can review it.');
    }

    public function type(): string
    {
        return 'feedback.not_a_verified_purchase';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
