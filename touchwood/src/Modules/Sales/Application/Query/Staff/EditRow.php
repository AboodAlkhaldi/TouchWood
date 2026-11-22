<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use DateTimeImmutable;

/** A staff edit of an order (sales.md §1.7): who, when, and the reason they wrote. */
final readonly class EditRow
{
    public function __construct(
        public string $editId,
        public string $reason,
        public ?string $by,
        public DateTimeImmutable $at,
    ) {}
}
