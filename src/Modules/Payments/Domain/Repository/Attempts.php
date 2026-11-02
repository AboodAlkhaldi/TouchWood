<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use DateTimeImmutable;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Public\Enums\PaymentMode;

interface Attempts
{
    /** The customer's details are kept for staff, and cleared on anonymizing (rule 13). */
    public function add(Attempt $attempt, ?string $customerName = null, ?string $customerEmail = null, ?string $customerPhone = null): void;

    public function find(string $id): ?Attempt;

    /** The attempt, its row locked to the end of the transaction. */
    public function lock(string $id): ?Attempt;

    public function save(Attempt $attempt, DateTimeImmutable $now): void;

    /** The owner's attempt still open (rule 3's unique index allows one), or null. */
    public function openOf(string $ownerId): ?Attempt;

    /** The owner's newest attempt, or null. */
    public function latestOf(string $ownerId): ?Attempt;

    public function byInvoice(PaymentMode $mode, string $invoiceId): ?Attempt;

    /**
     * Takes the one status call an attempt may have at a time (§1.8), for this long; false when another holds it.
     */
    public function claimCheck(string $id, DateTimeImmutable $now, DateTimeImmutable $until): bool;

    public function releaseCheck(string $id): void;

    /**
     * Attempts whose booked check is overdue - a lost job - oldest first (§9.6 #4).
     *
     * @return list<string> their ids
     */
    public function overdue(DateTimeImmutable $before, int $most): array;

    /** Clears the customer's name, email and phone (rule 13). */
    public function clearCustomer(string $buyerId, DateTimeImmutable $now): void;
}
