<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\NoticeAlreadyStarted;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Shared\Domain\ValueObject\StoreId;

/**
 * An offer notice (ops.md §1.7): staff telling every customer of a store - or the members of one of its
 * Promotions segments - about an offer, in both languages, by the bell, the email or both, now or at a
 * time chosen in the store's time zone.
 *
 * Its rules: at least one channel; a time to come when scheduled; changed or cancelled only while it
 * waits - once sending starts it cannot be recalled. The counts are the sender's, never set here.
 */
final readonly class Notice
{
    /** @throws InvalidOpsInput */
    public function __construct(
        public string $id,
        public StoreId $store,
        public WrittenText $text,
        public bool $byBell,
        public bool $byEmail,
        public BroadcastStatus $status,
        public DateTimeImmutable $sendAt,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $startedAt = null,
        public int $bellCount = 0,
        public int $emailCount = 0,
        public int $failedCount = 0,
        /** One of the store's Promotions segments; none: every customer of the store. */
        public ?string $segmentId = null,
        public ?DateTimeImmutable $finishedAt = null,
    ) {
        if (! $byBell && ! $byEmail) {
            throw new InvalidOpsInput('channels', 'the bell, the email, or both');
        }
    }

    /**
     * A new notice: sent now (it starts at once), or waiting for a time still to come.
     *
     * @throws InvalidOpsInput
     */
    public static function write(string $id, StoreId $store, WrittenText $text, bool $byBell, bool $byEmail, ?DateTimeImmutable $sendAt, string $staffId, DateTimeImmutable $now, ?string $segmentId = null): self
    {
        if ($sendAt !== null && $sendAt <= $now) {
            throw new InvalidOpsInput('send_at', 'a time still to come');
        }

        $segmentId = $segmentId === null ? null : strtolower($segmentId);

        return $sendAt === null
            ? new self(strtolower($id), $store, $text, $byBell, $byEmail, BroadcastStatus::Sending, $now, strtolower($staffId), $now, $now, $now, segmentId: $segmentId)
            : new self(strtolower($id), $store, $text, $byBell, $byEmail, BroadcastStatus::Scheduled, $sendAt, strtolower($staffId), $now, $now, segmentId: $segmentId);
    }

    /**
     * The same notice changed while it waits: now (it starts at once), or at another time to come.
     *
     * @throws InvalidOpsInput|NoticeAlreadyStarted
     */
    public function changed(WrittenText $text, bool $byBell, bool $byEmail, ?DateTimeImmutable $sendAt, DateTimeImmutable $now, ?string $segmentId = null): self
    {
        $this->waiting();

        $new = self::write($this->id, $this->store, $text, $byBell, $byEmail, $sendAt, $this->createdBy, $now, $segmentId);

        return new self($this->id, $this->store, $text, $byBell, $byEmail, $new->status, $new->sendAt, $this->createdBy, $this->createdAt, $now, $new->startedAt, segmentId: $new->segmentId);
    }

    /** @throws NoticeAlreadyStarted */
    public function cancelled(DateTimeImmutable $now): self
    {
        $this->waiting();

        return new self($this->id, $this->store, $this->text, $this->byBell, $this->byEmail, BroadcastStatus::Cancelled, $this->sendAt, $this->createdBy, $this->createdAt, $now, segmentId: $this->segmentId);
    }

    /** @throws NoticeAlreadyStarted */
    private function waiting(): void
    {
        if ($this->status !== BroadcastStatus::Scheduled) {
            throw new NoticeAlreadyStarted;
        }
    }
}
