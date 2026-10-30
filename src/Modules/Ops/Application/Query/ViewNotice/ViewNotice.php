<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewNotice;

/** One notice, as its form shows it (ops.md §1.7, §4). */
final readonly class ViewNotice
{
    public function __construct(
        public string $noticeId,
    ) {}
}
