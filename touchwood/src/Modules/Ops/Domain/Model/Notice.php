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
 *
 * **A draft** (§9.7 #1; content.md §1.7 #10) has no time: the scheduler never sends it, and a campaign that names
 * it starts it when the campaign goes live in its store. It is changed and cancelled as a waiting one.
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
        /** When it sends, or was sent; none for a draft alone. */
        public ?DateTimeImmutable $sendAt,
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
     * A new notice: sent now (it starts at once), waiting for a time still to come, or a draft with no time
     * (`$draft`: the time given is not read).
     *
     * @throws InvalidOpsInput
     */
    public static function write(string $id, StoreId $store, WrittenText $text, bool $byBell, bool $byEmail, ?DateTimeImmutable $sendAt, string $staffId, DateTimeImmutable $now, ?string $segmentId = null, bool $draft = false): self
    {
        $segmentId = $segmentId === null ? null : strtolower($segmentId);

        if ($draft) {
            return new self(strtolower($id), $store, $text, $byBell, $byEmail, BroadcastStatus::Draft, null, strtolower($staffId), $now, $now, segmentId: $segmentId);
        }

        if ($sendAt !== null && $sendAt <= $now) {
            throw new InvalidOpsInput('send_at', 'a time still to come');
        }

        return $sendAt === null
            ? new self(strtolower($id), $store, $text, $byBell, $byEmail, BroadcastStatus::Sending, $now, strtolower($staffId), $now, $now, $now, segmentId: $segmentId)
            : new self(strtolower($id), $store, $text, $byBell, $byEmail, BroadcastStatus::Scheduled, $sendAt, strtolower($staffId), $now, $now, segmentId: $segmentId);
    }

    /**
     * The same notice changed while it waits: now (it starts at once), at another time to come, or a draft.
     *
     * @throws InvalidOpsInput|NoticeAlreadyStarted
     */
    public function changed(WrittenText $text, bool $byBell, bool $byEmail, ?DateTimeImmutable $sendAt, DateTimeImmutable $now, ?string $segmentId = null, bool $draft = false): self
    {
        $this->waiting();

        $new = self::write($this->id, $this->store, $text, $byBell, $byEmail, $sendAt, $this->createdBy, $now, $segmentId, $draft);

        return new self($this->id, $this->store, $text, $byBell, $byEmail, $new->status, $new->sendAt, $this->createdBy, $this->createdAt, $now, $new->startedAt, segmentId: $new->segmentId);
    }

    /**
     * Started by its campaign going live (§1.7), a draft or whatever time it waited for: it goes now, and cannot
     * be recalled from here on.
     *
     * @throws NoticeAlreadyStarted
     */
    public function started(DateTimeImmutable $now): self
    {
        $this->waiting();

        return new self($this->id, $this->store, $this->text, $this->byBell, $this->byEmail, BroadcastStatus::Sending, $now, $this->createdBy, $this->createdAt, $now, $now, segmentId: $this->segmentId);
    }

    /** @throws NoticeAlreadyStarted */
    public function cancelled(DateTimeImmutable $now): self
    {
        $this->waiting();

        return new self($this->id, $this->store, $this->text, $this->byBell, $this->byEmail, BroadcastStatus::Cancelled, $this->sendAt, $this->createdBy, $this->createdAt, $now, segmentId: $this->segmentId);
    }

    /** Whether it still waits - for its time, or as a draft for a campaign: only then is it changed, cancelled or started. */
    public function waits(): bool
    {
        return $this->status === BroadcastStatus::Scheduled || $this->status === BroadcastStatus::Draft;
    }

    /** @throws NoticeAlreadyStarted */
    private function waiting(): void
    {
        if (! $this->waits()) {
            throw new NoticeAlreadyStarted;
        }
    }
}
