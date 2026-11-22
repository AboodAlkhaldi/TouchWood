<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * Where a smart bar shows (content.md §1.9): above one category's list and those below it, or above every list of
 * a campaign run's store while the run is live (`run_id`, which goes with its run).
 */
enum SmartBarScope: string
{
    case Category = 'CATEGORY';
    case Campaign = 'CAMPAIGN';
}
