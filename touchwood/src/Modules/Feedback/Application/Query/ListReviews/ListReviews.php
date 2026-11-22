<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListReviews;

/** A store's reviews in one state, a page at a time (feedback.md §1.1, §3). */
final readonly class ListReviews
{
    public function __construct(
        public string $storeId,
        public string $status,
        public string $locale,
        public ?string $after = null,
        /** Each state's count too, for the tabs (round 3 #11): one more query, so only the list's first page asks. */
        public bool $counted = false,
    ) {}
}
