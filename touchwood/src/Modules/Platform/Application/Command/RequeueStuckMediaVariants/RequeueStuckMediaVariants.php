<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RequeueStuckMediaVariants;

final readonly class RequeueStuckMediaVariants
{
    /**
     * @param  positive-int  $limit  the most images queued again in one run
     */
    public function __construct(
        public int $limit = 100,
    ) {}
}
