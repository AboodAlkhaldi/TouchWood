<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RefuseReturn;

final readonly class RefuseReturn
{
    public function __construct(
        public string $returnId,
        public string $reason,
    ) {}
}
