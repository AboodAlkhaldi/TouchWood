<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Enums;

/**
 * A review's mark (feedback.md §1.1): "Verified purchase", flipped for good to "Returned" once the
 * customer returned any of that product — also after the review was written.
 */
enum ReviewMark: string
{
    case VerifiedPurchase = 'VERIFIED_PURCHASE';
    case Returned = 'RETURNED';
}
