<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RequestReturn;

final readonly class RequestReturn
{
    /**
     * @param  list<array{orderLineId: string, quantity: int}>  $lines
     */
    public function __construct(
        public string $orderId,
        public string $reason,
        public ?string $other,
        public array $lines,
    ) {}
}
