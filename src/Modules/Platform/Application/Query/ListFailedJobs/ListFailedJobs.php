<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListFailedJobs;

/**
 * Every failed job, oldest first (platform.md §3). Not paged: each stays until an admin handles it,
 * so the list is the backlog, and a long one is itself the thing to see.
 */
final readonly class ListFailedJobs {}
