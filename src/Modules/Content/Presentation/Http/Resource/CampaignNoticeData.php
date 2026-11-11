<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A notice a run may send once on going live (content.md §1.7 #10): its title in both languages and its status in Ops
 * (`GONE` for one deleted there).
 */
#[TypeScript]
final class CampaignNoticeData extends Data
{
    public function __construct(
        public string $id,
        public ?string $titleAr,
        public ?string $titleEn,
        public string $status,
    ) {}
}
