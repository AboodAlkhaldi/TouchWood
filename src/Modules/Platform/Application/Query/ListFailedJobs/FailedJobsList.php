<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListFailedJobs;

/**
 * One page of failed jobs, and where the next one starts — null when this is the last.
 */
final readonly class FailedJobsList
{
    /**
     * @param  list<FailedJobRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextFailedAt,
        public ?string $nextId,
    ) {}
}
