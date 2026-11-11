<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W5b, a theme's page (content.md §4.4): New Theme, or one that exists with its parts, every image as its box shows
 * it, the campaigns linked to it, and whether this browser previews it now.
 */
#[TypeScript]
final class ThemeFormPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  list<ThemePartData>  $parts
     * @param  array<string, ContentImageData>  $images  by media id
     * @param  list<NamedChoiceData>  $campaigns
     */
    public function __construct(
        public array $stores,
        public ?ThemeData $themeItem,
        public array $parts,
        public array $images,
        public array $campaigns,
        public bool $translationReady,
        public bool $previewing,
    ) {}
}
