<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ReceiveReturn;

final readonly class ReceiveReturn
{
    /**
     * @param  list<array{orderLineId: string, received: int, restocked: int}>  $lines  every line of the return, once
     */
    public function __construct(
        public string $returnId,
        public array $lines,
    ) {}
}
