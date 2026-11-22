<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\AcceptReturn;

final readonly class AcceptReturn
{
    public function __construct(
        public string $returnId,
    ) {}
}
