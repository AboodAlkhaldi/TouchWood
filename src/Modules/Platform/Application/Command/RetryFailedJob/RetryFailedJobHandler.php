<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetryFailedJob;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\FailedJobAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\FailedJobs\FailedJobs;
use Modules\Platform\Domain\Exception\FailedJobNotFound;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * An admin puts one failed job back on its queue (platform.md §3): it runs again, its attempts
 * counted afresh, and leaves the list; if it fails again it comes back. One transaction — the row
 * locked, the job queued, the row deleted, the audit entry — so it is never both queued and listed,
 * and a second retry of the same job finds it gone.
 */
final readonly class RetryFailedJobHandler
{
    public const string PERMISSION = PlatformPermissions::JOBS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private FailedJobs $failedJobs,
        private AuditLog $auditLog,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws FailedJobNotFound
     */
    public function handle(RetryFailedJob $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            $job = $this->failedJobs->lock($command->id) ?? throw new FailedJobNotFound($command->id);

            $this->failedJobs->requeue($job);
            $this->failedJobs->forget($job->id);
            $this->auditLog->record(FailedJobAudit::retried($job));
        });
    }
}
