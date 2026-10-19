<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/** A page of a store's review queue: `next` is what to ask for the following page, null on the last. */
final readonly class ReviewsPage
{
    /**
     * @param  list<ReviewRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
