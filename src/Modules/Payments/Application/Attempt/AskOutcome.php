<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Modules\Payments\Domain\Model\Attempt;

/**
 * What came of an ask: the attempt as it now stands, whether another call held it (`busy`), and whether anything was
 * `learned`. Whatever depends on an answer - a booked check, a notice - tries again unless it was.
 */
final readonly class AskOutcome
{
    public function __construct(
        public Attempt $attempt,
        public bool $busy = false,
        public bool $learned = false,
    ) {}
}
