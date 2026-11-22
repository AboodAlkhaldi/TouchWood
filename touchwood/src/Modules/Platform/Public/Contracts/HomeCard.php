<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeScope;

/**
 * What one card on the admin home shows (platform.md §2.6). Named on its entry (HomeCardDto::$card)
 * and resolved only for a reader the card is offered to; a figure needing a permission of its own
 * asks the Shared Authorizer itself.
 */
interface HomeCard
{
    /** What the card shows for the scope, or null when it has nothing to say there. */
    public function data(HomeScope $scope): ?HomeCardData;
}
