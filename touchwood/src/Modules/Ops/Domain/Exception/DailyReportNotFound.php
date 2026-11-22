<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An unknown daily report, or one the reader may not open - another store's, or every store's to anyone
 * but a super admin: the same answer for all (ops.md §1.11, §7).
 */
final class DailyReportNotFound extends OpsError
{
    public function __construct()
    {
        parent::__construct('No such daily report.');
    }

    public function type(): string
    {
        return 'ops.daily_report_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
