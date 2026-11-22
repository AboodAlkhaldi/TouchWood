<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\RejectReview;

final readonly class RejectReview
{
    public function __construct(
        public string $reviewId,
        public ?string $reason = null,
    ) {}
}
