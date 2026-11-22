<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\CustomerActions;

/**
 * What the person acting now may do to one customer (frontend.md §3.7, G2).
 *
 * Four things, and no more: a customer's profile is their own, and staff never edit it.
 */
final readonly class CustomerActionsDto
{
    public function __construct(
        public bool $mayBlock,
        public bool $mayUnblock,
        public bool $mayStartDeletion,
        public bool $mayCancelDeletion,
    ) {}

    public static function none(): self
    {
        return new self(false, false, false, false);
    }
}
