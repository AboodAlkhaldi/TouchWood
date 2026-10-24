<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\BulkDecideReviews;

/** A decision on many reviews of a store at once (feedback.md §2.4 B2): approve, reject, unpublish. */
final readonly class BulkDecideReviews
{
    /**
     * @param  list<string>  $reviewIds
     */
    public function __construct(
        public string $storeId,
        public string $action,
        public array $reviewIds,
        /** One optional reason for all, rejecting or unpublishing; the customer sees it. */
        public ?string $reason = null,
    ) {}
}
