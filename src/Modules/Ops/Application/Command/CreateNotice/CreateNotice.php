<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\CreateNotice;

/**
 * A new offer notice for every customer of a store (ops.md §1.7): its title and line in both
 * languages, an optional page of the shop, the channels, and when - none to send now, else a date and
 * time in the store's time zone ("2026-11-01 09:30").
 */
final readonly class CreateNotice
{
    public function __construct(
        public string $storeId,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public ?string $link,
        public bool $byBell,
        public bool $byEmail,
        public ?string $sendAt,
        /** One of the store's Promotions segments (§1.7); none: every customer of the store. */
        public ?string $segmentId = null,
    ) {}
}
