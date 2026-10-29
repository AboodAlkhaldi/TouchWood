<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\FindCustomersToMessage;

/**
 * A customer the composer offers (ops.md §1.8): the name, and the email only when the reader may view
 * that customer (access.md amendment 44(b)) - otherwise null.
 */
final readonly class CustomerToMessage
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public ?string $email,
    ) {}
}
