<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\External;

use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Public\Contracts\ContentApi;
use Modules\Content\Public\Dto\CampaignMessageDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;

/**
 * What other modules ask Content (content.md §2.1). `campaignForMessage` is kept exactly as Ops is built against it:
 * **`$id` is a run's id** (§1.7 #13) - `campaignId` the run, `storeId` its store, the name the campaign's in both
 * languages - and the answer is **null for an unknown id and for a run that is not live**, so an alert queued just
 * before End or Cancel sends nothing. One query.
 */
final readonly class ContentApiImpl implements ContentApi
{
    public function __construct(
        private Campaigns $campaigns,
    ) {}

    public function campaignForMessage(string $id): ?CampaignMessageDto
    {
        $run = $this->campaigns->namesOf([$id])[strtolower(trim($id))] ?? null;

        if ($run === null || $run['state'] !== RunState::Live) {
            return null;
        }

        // English is required before a run is scheduled, so a live one has it; Arabic stands in if not.
        return new CampaignMessageDto(strtolower(trim($id)), $run['storeId'], new TranslatedTextDto($run['nameAr'], $run['nameEn'] ?? $run['nameAr']));
    }
}
