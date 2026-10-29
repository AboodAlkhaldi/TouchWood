<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a staff member wrote to customers of a store (ops.md §1.8): to one customer - a service
 * message - or to every customer of the store - marketing, for its email. By the bell, the email or
 * both; recorded with who sent it, to whom, and when. Nothing can be recalled.
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
    ) {
        if (! $byBell && ! $byEmail) {
            throw new InvalidOpsInput('channels', 'the bell, the email, or both');
        }

        if ($text->link !== null) {
            throw new InvalidOpsInput('link', 'no link: a message is its text');
        }
    }

    /** To one customer, else to every customer of the store. */
    public function isToOne(): bool
    {
        return $this->customerId !== null;
    }
}
