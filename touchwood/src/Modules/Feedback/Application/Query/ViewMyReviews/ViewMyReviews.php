<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ViewMyReviews;

/** My reviews, a page at a time: after the last one shown, or from the newest. */
final readonly class ViewMyReviews
{
    public function __construct(
        public string $locale,
        public ?string $after = null,
    ) {}
}
