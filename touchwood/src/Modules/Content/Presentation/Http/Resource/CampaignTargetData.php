<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a campaign's price names, by name (content.md §1.7 #4): a product, size, category or brand with its percentage
 * or, a size, its fixed price; an archived one marked so (§1.1 rule 17).
 */
#[TypeScript]
final class CampaignTargetData extends Data
{
    public function __construct(
        public string $kind,
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public ?string $detail,
        public bool $archived,
        public ?int $percentBp,
        public ?int $amountMinor,
    ) {}
}
