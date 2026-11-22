<?php

declare(strict_types=1);

namespace Modules\Content\Public\Dto;

use Modules\Platform\Public\Dto\TranslatedTextDto;

/** A campaign as Ops' alert about it needs it (`ContentApi::campaignForMessage`; ops.md §1.6 S8). */
final readonly class CampaignMessageDto
{
    public function __construct(
        public string $campaignId,
        public string $storeId,
        public TranslatedTextDto $name,
    ) {}
}
