<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Dto;

/** A page of `FeedbackApi::favouriteCounts`, and the cursor of the page after it (none on the last). */
final readonly class FavouriteCountsDto
{
    /**
     * @param  list<FavouriteCountDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
