<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RestorePlace;

/**
 * Bring a retired place back (stage 7 step 1, Q3). Super Admin only.
 */
final readonly class RestorePlace
{
    public function __construct(
        public string $placeId,
    ) {}
}
