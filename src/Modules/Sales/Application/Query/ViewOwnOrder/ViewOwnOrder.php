<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewOwnOrder;

final readonly class ViewOwnOrder
{
    public function __construct(
        public string $number,
    ) {}
}
