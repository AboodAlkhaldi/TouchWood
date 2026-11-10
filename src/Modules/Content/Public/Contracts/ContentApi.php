<?php

declare(strict_types=1);

namespace Modules\Content\Public\Contracts;

use Modules\Content\Public\Dto\CampaignMessageDto;

/**
 * What other modules may ask Content (handoff §13.2; content.md §2.1). Declared ahead of its build, so Ops' side of
 * the campaigns could be finished first, and kept exactly as Ops is built against it.
 */
interface ContentApi
{
    /**
     * A campaign as Ops' alert about it needs it (ops.md §1.6 S8): `$id` is a **run's** id (content.md §1.7 #13), the
     * answer that run, its store and its campaign's name. Null for an unknown id **and for a run not live**, and then
     * nothing is sent.
     */
    public function campaignForMessage(string $id): ?CampaignMessageDto;
}
