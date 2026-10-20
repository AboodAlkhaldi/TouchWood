<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CompleteReturn;

final readonly class CompleteReturn
{
    public function __construct(
        public string $returnId,
    ) {}
}
