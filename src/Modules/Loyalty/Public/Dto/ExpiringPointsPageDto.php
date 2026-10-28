<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

/** A page of `LoyaltyApi::expiring`, and the cursor of the page after it (none on the last). */
final readonly class ExpiringPointsPageDto
{
    /**
     * @param  list<ExpiringPointsDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
