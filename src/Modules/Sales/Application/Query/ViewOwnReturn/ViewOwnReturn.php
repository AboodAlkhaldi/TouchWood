<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewOwnReturn;

final readonly class ViewOwnReturn
{
    public function __construct(
        public string $returnId,
    ) {}
}
