<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A daily report email set for 00:00 (ops.md §1.11, §7; owner, 2026-11-01): its day has only just started,
 * so the report would hold nothing.
 */
final class DailyEmailAtMidnight extends OpsError
{
    public function __construct()
    {
        parent::__construct('A daily report email cannot go at 00:00.');
    }

    public function type(): string
    {
        return 'ops.daily_email_at_midnight';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
