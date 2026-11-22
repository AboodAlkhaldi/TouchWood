<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SetDailyReportEmail;

/**
 * "Email me the daily report" (ops.md §1.11): on or off, at `sendAt` (`HH:MM`) in the store's time
 * zone - one store's (`store`), or every store's (null, super admins only, the base store's time).
 */
final readonly class SetDailyReportEmail
{
    public function __construct(
        public ?string $store,
        public bool $on,
        public string $sendAt,
    ) {}
}
