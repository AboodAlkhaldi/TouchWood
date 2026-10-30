<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListDailyReports;

/** The daily reports kept (ops.md §1.11), newest first, a page after the row `after` names. */
final readonly class ListDailyReports
{
    public function __construct(
        public ?string $after = null,
    ) {}
}
