<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListFailedJobs;

/**
 * One page of the failed jobs, oldest first (platform.md §3): 50 at a time, as the media library and
 * the audit log page, after the last job of the page before — the time it failed and its id.
 */
final readonly class ListFailedJobs
{
    public const int PER_PAGE = 50;

    public function __construct(
        /** ISO 8601, as the page before gave it. */
        public ?string $afterFailedAt = null,
        public ?string $afterId = null,
        public int $perPage = self::PER_PAGE,
    ) {}
}
