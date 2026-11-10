<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Modules\Content\Application\ContentJobs;

/** `ContentJobs` as Laravel's queue: each job queued once the asking transaction commits. */
final class QueuedContentJobs implements ContentJobs
{
    public function buildSitemaps(): void
    {
        BuildSitemapsJob::dispatch()->afterCommit();
    }

    public function checkRedirects(?string $after = null): void
    {
        CheckRedirectsJob::dispatch($after)->afterCommit();
    }
}
