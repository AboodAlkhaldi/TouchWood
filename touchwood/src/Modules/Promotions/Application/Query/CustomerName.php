<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

/**
 * A customer as Promotions' screens name them (promotions.md §1.5: the name only, through Access's
 * bulk read, amendment 70) — an anonymized account reads as "Deleted customer" on the screen.
 */
final readonly class CustomerName
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public bool $anonymized,
    ) {}
}
