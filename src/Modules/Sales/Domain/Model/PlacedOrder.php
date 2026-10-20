<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/** An order placed: its id, and its public number — the one a customer sees (sales.md §1.3). */
final readonly class PlacedOrder
{
    public function __construct(
        public string $id,
        public string $number,
    ) {}
}
