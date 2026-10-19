<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\ApproveReview;

final readonly class ApproveReview
{
    public function __construct(
        public string $reviewId,
    ) {}
}
