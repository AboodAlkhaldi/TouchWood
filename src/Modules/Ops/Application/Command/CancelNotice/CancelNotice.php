<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\CancelNotice;

/** A waiting notice cancelled before it starts sending (ops.md §1.7). */
final readonly class CancelNotice
{
    public function __construct(
        public string $noticeId,
    ) {}
}
