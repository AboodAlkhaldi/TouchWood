<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\MakeDailyReport;

/**
 * "Make the daily report" (ops.md §1.11): one store's, or every store's (`store` null, super admins
 * only); today's until now, or a past day (`day`, `YYYY-MM-DD`).
 */
final readonly class MakeDailyReport
{
    public function __construct(
        public ?string $store,
        public ?string $day = null,
    ) {}
}
