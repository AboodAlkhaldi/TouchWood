<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One staff message as recorded (ops.md §1.8, §4): who sent it - named as the reader may see them - to
 * whom, what it said in both languages, its channels, its status (SENDING, SENT, FAILED), and how many it
 * reached so far: bell entries, emails sent, emails failed.
 */
#[TypeScript]
final class StaffMessageData extends Data
{
    public function __construct(
        public string $id,
        public string $storeCode,
        /** `ONE`, `STORE` or `SEGMENT`. */
        public string $audience,
        public ?string $customerId,
        /** The customer's name, for a message to one; empty if Access no longer knows them. */
        public ?string $customerName,
        public ?string $segmentId,
        /** The segment's name now; null with a `segmentId` set means it was deleted since. */
        public ?string $segmentNameAr,
        public ?string $segmentNameEn,
        /** How many it holds now, not how many the message reached; null as the name is. */
        public ?int $segmentMemberCount,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public bool $byBell,
        public bool $byEmail,
        public string $status,
        public string $sentBy,
        public int $bellCount,
        public int $emailCount,
        public int $failedCount,
        public string $createdAt,
        public ?string $finishedAt,
    ) {}
}
