<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;
use Modules\Loyalty\Public\Enums\EntryKind;

/**
 * One entry of an account's history (loyalty.md §3): what moved and when, the order's public number
 * (never its id), and for a change by hand the admin and the reason — staff read those, the customer
 * does not. A Super Admin's name is withheld from anyone else (`staffHidden`; handoff §14).
 */
final readonly class PointsHistoryRow
{
    public function __construct(
        public int $id,
        public EntryKind $kind,
        public int $points,
        public DateTimeImmutable $occurredAt,
        public ?string $orderNumber,
        public ?string $reason = null,
        public ?string $staffId = null,
        public ?string $staffName = null,
        public bool $staffHidden = false,
    ) {}

    /** The row as a customer reads it: no admin, no reason. */
    public function forCustomer(): self
    {
        return new self($this->id, $this->kind, $this->points, $this->occurredAt, $this->orderNumber);
    }

    /** Named as the reader may see the admin: a withheld one keeps no id, so nothing links to them. */
    public function named(?string $name, bool $hidden): self
    {
        return new self($this->id, $this->kind, $this->points, $this->occurredAt, $this->orderNumber, $this->reason, $hidden ? null : $this->staffId, $name, $hidden);
    }
}
