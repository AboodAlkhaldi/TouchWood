<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\Earn;

use Modules\Loyalty\Public\Dto\EarnPoints;

/** An order delivered (loyalty.md §1.6), inside Sales's transaction. */
final readonly class Earn
{
    public function __construct(
        public EarnPoints $change,
    ) {}
}
