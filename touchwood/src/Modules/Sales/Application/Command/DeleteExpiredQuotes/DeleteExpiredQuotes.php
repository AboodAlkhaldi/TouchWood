<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\DeleteExpiredQuotes;

/** The nightly deletion of quotes past their expiry (sales.md §5) — the system's only. */
final readonly class DeleteExpiredQuotes
{
    public function __construct(
    ) {}
}
