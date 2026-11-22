<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Another offer on a run's sizes in its dates (pricing.md §9.6 #8): a sale, a category discount or another campaign's
 * run, its dates, whether it always wins, how many sizes it shares.
 */
#[TypeScript]
final class CampaignOfferData extends Data
{
    public function __construct(
        public string $kind,
        public string $id,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public int $sizes,
    ) {}
}
