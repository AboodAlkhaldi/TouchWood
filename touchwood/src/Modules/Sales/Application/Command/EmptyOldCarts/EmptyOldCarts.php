<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\EmptyOldCarts;

/** The nightly emptying of carts no one touched for 90 days (sales.md §1.1). */
final readonly class EmptyOldCarts
{
    public function __construct(
    ) {}
}
