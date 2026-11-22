<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\FindCustomersToMessage;

/** The composer's search (ops.md §1.8): a customer to write to, found by name, email or phone - 3 to 100 characters. */
final readonly class FindCustomersToMessage
{
    public function __construct(
        public string $storeId,
        public string $text,
    ) {}
}
