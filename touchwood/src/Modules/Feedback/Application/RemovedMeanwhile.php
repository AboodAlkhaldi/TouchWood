<?php

declare(strict_types=1);

namespace Modules\Feedback\Application;

use Illuminate\Database\QueryException;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;

/**
 * A product (or the variant a review shows) Catalog deleted between the check and the write breaks
 * the row's foreign key: answered as the check would have — the product is not sold here — never as a
 * database error. Anything else is not Feedback's to name.
 */
final class RemovedMeanwhile
{
    public static function of(QueryException $failed): InvalidFeedbackAttribute|QueryException
    {
        return $failed->getCode() === '23503' && preg_match('/\b(reviews_product|reviews_variant|questions_product|wishlist_items_product)\b/', $failed->getPrevious()?->getMessage() ?? $failed->getMessage()) === 1
            ? new InvalidFeedbackAttribute('product', 'removed from the catalog meanwhile')
            : $failed;
    }
}
