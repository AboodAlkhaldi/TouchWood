<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListStaffMessages;

use DateTimeImmutable;

/** One staff message as recorded (ops.md §1.8, §5): who sent it, to whom, the channels, how far it got. */
final readonly class StaffMessageRow
{
    public function __construct(
        public string $id,
        public string $storeId,
        public string $senderId,
        /** `ONE`, `STORE` or `SEGMENT`. */
        public string $audience,
        public ?string $customerId,
        public ?string $segmentId,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public bool $byBell,
        public bool $byEmail,
        /** `SENDING`, `SENT` or `FAILED`. */
        public string $status,
        public int $bellCount,
        public int $emailCount,
        public int $failedCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}
}
