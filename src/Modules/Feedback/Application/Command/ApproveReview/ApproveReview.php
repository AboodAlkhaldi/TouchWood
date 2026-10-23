<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\ApproveReview;

final readonly class ApproveReview
{
    public function __construct(
        public string $reviewId,
        /** The text's language as staff set it on approving (§4.2 B3); null keeps the one worked out. */
        public ?string $locale = null,
    ) {}
}
