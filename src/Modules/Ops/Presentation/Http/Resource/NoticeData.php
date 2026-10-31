<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One offer notice (ops.md §1.7, §4): what it says in both languages, its page of the shop (written
 * without store and language), its channels, its status (SCHEDULED, SENDING, SENT, CANCELLED), when it
 * sends - `sendAtLocal` in the store's time zone as the form shows it, `sendAt` in ISO 8601 UTC - and
 * how many it reached: bell entries, emails sent, emails failed.
 */
#[TypeScript]
final class NoticeData extends Data
{
    public function __construct(
        public string $id,
        public string $storeCode,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public ?string $link,
        public bool $byBell,
        public bool $byEmail,
        public string $status,
        public string $sendAt,
        public string $sendAtLocal,
        public bool $changeable,
        public int $bellCount,
        public int $emailCount,
        public int $failedCount,
        public string $createdAt,
        /** One of the store's Promotions segments (§1.7); null: every customer of the store. */
        public ?string $segmentId,
    ) {}
}
