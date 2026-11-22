<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use DateTimeImmutable;

/** A step of an order's history (sales.md §5): what happened, when, by whom (a staff name; null the customer or the system). */
final readonly class HistoryRow
{
    /**
     * @param  array<string, mixed>  $detail  ids and codes only — never anyone's words
     */
    public function __construct(
        public string $kind,
        public DateTimeImmutable $at,
        public ?string $by,
        public ?string $editId,
        public array $detail,
    ) {}
}
