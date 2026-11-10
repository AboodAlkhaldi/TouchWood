<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A home section's own page (content.md §1.4, §4.4 W1 Edit): its fields by type, the logos band's logos, the icons on offer. */
#[TypeScript]
final class HomeSectionPageData extends Data
{
    /**
     * @param  list<HomeLogoData>  $logos
     * @param  list<string>  $icons
     */
    public function __construct(
        public ContentStoreData $store,
        public HomeSectionData $section,
        public array $logos,
        public array $icons,
        public bool $translationReady,
    ) {}
}
