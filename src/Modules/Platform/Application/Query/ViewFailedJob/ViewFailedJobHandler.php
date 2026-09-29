<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ViewFailedJob;

use Modules\Platform\Application\FailedJobs\FailedJob;
use Modules\Platform\Application\FailedJobs\FailedJobs;
use Modules\Platform\Domain\Exception\FailedJobNotFound;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * One failed job with its whole error (platform.md §3, frontend.md E7) — which may quote the values
 * the job was writing, hence the admin-only permission.
 */
final readonly class ViewFailedJobHandler
{
    public const string PERMISSION = PlatformPermissions::JOBS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private FailedJobs $failedJobs,
    ) {}

    /**
     * @throws FailedJobNotFound
     */
    public function handle(ViewFailedJob $query): FailedJob
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return $this->failedJobs->find($query->id) ?? throw new FailedJobNotFound($query->id);
    }
}
