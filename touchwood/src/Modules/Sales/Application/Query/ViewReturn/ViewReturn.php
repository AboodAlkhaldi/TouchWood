<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewReturn;

final readonly class ViewReturn
{
    public function __construct(
        public string $returnId,
    ) {}
}
