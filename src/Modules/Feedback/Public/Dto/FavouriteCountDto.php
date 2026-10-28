<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Dto;

/** One product of `FeedbackApi::favouriteCounts`: how many customers saved it in the store. */
final readonly class FavouriteCountDto
{
    public function __construct(
        public string $productId,
        public int $customers,
    ) {}
}
