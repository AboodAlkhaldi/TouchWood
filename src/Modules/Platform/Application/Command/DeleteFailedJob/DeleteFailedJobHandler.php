<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteFailedJob;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\FailedJobAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\FailedJobs\FailedJobs;
use Modules\Platform\Domain\Exception\FailedJobNotFound;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * An admin removes one failed job for good, unrun (platform.md §3) — one that is obsolete, say. Its
 * row locked first, so a retry and a delete of the same job cannot both happen.
 */
final readonly class DeleteFailedJobHandler
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
    public function handle(DeleteFailedJob $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            $job = $this->failedJobs->lock($command->id) ?? throw new FailedJobNotFound($command->id);

            $this->failedJobs->forget($job->id);
            $this->auditLog->record(FailedJobAudit::deleted($job));
        });
    }
}
