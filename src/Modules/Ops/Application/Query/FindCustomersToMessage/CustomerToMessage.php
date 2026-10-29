<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\FindCustomersToMessage;

/** A customer the composer offers (ops.md §1.8): one the reader may view and write to, with their name and email. */
final readonly class CustomerToMessage
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
    ) {}
}
