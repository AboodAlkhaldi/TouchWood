<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * Where a smart bar shows (content.md §1.9): above one category's list and those below it, or above every list of
 * a campaign run's store while the run is live. Campaign runs arrive with campaigns (plan PR 6): until then no
 * campaign bar can be made.
 */
enum SmartBarScope: string
{
    case Category = 'CATEGORY';
    case Campaign = 'CAMPAIGN';
}
