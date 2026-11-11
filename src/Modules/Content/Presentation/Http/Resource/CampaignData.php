<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A campaign's shared fields as W4b edits them (content.md §1.7 #1): names, start and end as typed, the theme link,
 * the version read, its badge (one state or Mixed), whether any run has gone live (half live, #8) and Translate's
 * unread mark (§1.3 #4).
 */
#[TypeScript]
final class CampaignData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public string $startsLocal,
        public string $endsLocal,
        public ?string $themeId,
        public int $version,
        public string $state,
        public bool $halfLive,
        public bool $translationUnread,
    ) {}
}
