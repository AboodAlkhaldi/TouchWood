<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CloseReturn;

final readonly class CloseReturn
{
    public function __construct(
        public string $returnId,
    ) {}
}
