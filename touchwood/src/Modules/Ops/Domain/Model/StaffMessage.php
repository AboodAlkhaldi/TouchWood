<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a staff member wrote to customers of a store (ops.md §1.8): to one customer - a service
 * message - or to every customer of the store, or the members of one of its segments - marketing, for
 * its email. By the bell, the email or both; recorded with who sent it, to whom, and when. Nothing can be
 * recalled.
 */
final readonly class StaffMessage
{
    /** @throws InvalidOpsInput */
    public function __construct(
        public string $id,
        public StoreId $store,
        public string $senderId,
        public ?string $customerId,
        public WrittenText $text,
        public bool $byBell,
        public bool $byEmail,
        public DateTimeImmutable $createdAt,
        /** One of the store's Promotions segments; none with a customer, or for every customer of the store. */
        public ?string $segmentId = null,
    ) {
        if ($customerId !== null && $segmentId !== null) {
            throw new InvalidOpsInput('audience', 'one customer, or one segment');
        }

        if (! $byBell && ! $byEmail) {
            throw new InvalidOpsInput('channels', 'the bell, the email, or both');
        }

        if ($text->link !== null) {
            throw new InvalidOpsInput('link', 'no link: a message is its text');
        }
    }

    /** To one customer, else to many. */
    public function isToOne(): bool
    {
        return $this->customerId !== null;
    }

    /** `ONE`, `SEGMENT` or `STORE`, as `ops.staff_messages` keeps it. */
    public function audience(): string
    {
        return match (true) {
            $this->customerId !== null => 'ONE',
            $this->segmentId !== null => 'SEGMENT',
            default => 'STORE',
        };
    }
}
