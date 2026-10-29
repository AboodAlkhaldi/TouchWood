<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\ChangeNotice;

/**
 * A waiting notice changed (ops.md §1.7): everything it was written with again - none for `sendAt`
 * sends it now.
 */
final readonly class ChangeNotice
{
    public function __construct(
        public string $noticeId,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public ?string $link,
        public bool $byBell,
        public bool $byEmail,
        public ?string $sendAt,
    ) {}
}
