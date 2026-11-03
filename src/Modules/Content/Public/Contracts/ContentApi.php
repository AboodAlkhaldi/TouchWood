<?php

declare(strict_types=1);

namespace Modules\Content\Public\Contracts;

use Modules\Content\Public\Dto\CampaignMessageDto;

/**
 * What other modules may ask Content (handoff §13.2). Declared ahead of its build, so Ops' side of the
 * campaigns could be finished first; `NoCampaignsYet` answers until then.
 */
interface ContentApi
{
    /** A campaign as Ops' alert about it needs it (ops.md §1.6 S8); null when there is none, and then nothing is sent. */
    public function campaignForMessage(string $id): ?CampaignMessageDto;
}
