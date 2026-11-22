<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Audit;

use Modules\Platform\Application\FailedJobs\FailedJob;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * What handling a failed job leaves in the audit log (platform.md §3): which job, and when it had
 * failed — **never its error or what it carried**, which may hold personal data, and the log is kept
 * forever. A job belongs to no store.
 */
final class FailedJobAudit
{
    private const string SUBJECT = 'platform.failed_job';

    public static function retried(FailedJob $job): AuditEntryDto
    {
        return self::entry('platform.failed_job.retried', $job);
    }

    public static function deleted(FailedJob $job): AuditEntryDto
    {
        return self::entry('platform.failed_job.deleted', $job);
    }

    private static function entry(string $action, FailedJob $job): AuditEntryDto
    {
        return new AuditEntryDto($action, self::SUBJECT, $job->id, null, AuditChanges::none()
            ->changed('job', $job->className(), null)
            ->changed('failed_at', $job->failedAt->format(DATE_ATOM), null));
    }
}
