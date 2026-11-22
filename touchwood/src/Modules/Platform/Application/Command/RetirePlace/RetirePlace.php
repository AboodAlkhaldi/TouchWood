<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetirePlace;

/**
 * Retire a place: it leaves every picker, with everything under it (shipping.md §9.3 #4; stage 7 step 1, Q4). Super Admin only.
 */
final readonly class RetirePlace
{
    public function __construct(
        public string $placeId,
    ) {}
}
