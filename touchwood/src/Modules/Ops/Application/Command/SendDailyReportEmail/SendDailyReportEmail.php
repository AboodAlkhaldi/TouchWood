<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SendDailyReportEmail;

use DateTimeImmutable;

/** The daily report's email due at `due` for one setting, as queued for its `version` (ops.md §1.11). */
final readonly class SendDailyReportEmail
{
    public function __construct(
        public string $settingId,
        public int $version,
        public DateTimeImmutable $due,
    ) {}
}
