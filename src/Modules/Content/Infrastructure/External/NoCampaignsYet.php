<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\External;

use Modules\Content\Public\Contracts\ContentApi;
use Modules\Content\Public\Dto\CampaignMessageDto;

/** `ContentApi` before Content is built: no campaign exists, so Ops' alert sends nothing. Content's build replaces it. */
final readonly class NoCampaignsYet implements ContentApi
{
    public function campaignForMessage(string $id): ?CampaignMessageDto
    {
        return null;
    }
}
