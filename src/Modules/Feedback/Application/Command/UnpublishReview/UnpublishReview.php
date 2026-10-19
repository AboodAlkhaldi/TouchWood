<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\UnpublishReview;

final readonly class UnpublishReview
{
    public function __construct(
        public string $reviewId,
        public ?string $reason = null,
    ) {}
}
