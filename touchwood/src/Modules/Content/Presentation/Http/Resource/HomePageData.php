<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W1 · Home Page (content.md §4.4): the store's sections from top to bottom, the types Add Section may offer, the footer. */
#[TypeScript]
final class HomePageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  list<HomeSectionData>  $sections
     * @param  list<string>  $addable
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        /** `sections` or `footer`. */
        public string $tab,
        public array $sections,
        public array $addable,
        public FooterData $footer,
        public bool $translationReady,
    ) {}
}
