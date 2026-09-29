<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListFailedJobs;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Platform\Application\FailedJobs\FailedJobs;
use Modules\Platform\Application\FailedJobs\FailedJobSummary;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Throwable;

/**
 * The failed jobs screen's list (platform.md §3, frontend.md E7): what ran, when it failed, the tries
 * it was allowed, the error's first line, and whether it can be retried — a page at a time, read
 * without the payloads or the whole errors. The whole error is ViewFailedJob's.
 */
final readonly class ListFailedJobsHandler
{
    public const string PERMISSION = PlatformPermissions::JOBS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private FailedJobs $failedJobs,
    ) {}

    public function handle(ListFailedJobs $query): FailedJobsList
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $perPage = min(max($query->perPage, 1), 100);
        // One more than a page, to learn whether there is another without counting them all.
        $jobs = $this->failedJobs->page(self::cursor($query->afterFailedAt), $query->afterId, $perPage + 1);
        $more = count($jobs) > $perPage;
        $jobs = array_slice($jobs, 0, $perPage);
        // With more to come the page is full, so its last job is at $perPage - 1.
        $last = $more ? ($jobs[$perPage - 1] ?? null) : null;

        return new FailedJobsList(
            array_map(fn (FailedJobSummary $job): FailedJobRow => new FailedJobRow(
                $job->id, $job->className, $job->failedAt, $job->triesAllowed, $job->queue, $job->errorLine,
                $this->failedJobs->retryable($job->connection),
            ), $jobs),
            $last?->failedAt->format(DateTimeInterface::ATOM),
            $last?->id,
        );
    }

    /**
     * A cursor that is not a time starts from the beginning, as a missing one does: it came from a
     * link, not from anything a person typed.
     */
    private static function cursor(?string $at): ?CarbonImmutable
    {
        if ($at === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($at);
        } catch (Throwable) {
            return null;
        }
    }
}
