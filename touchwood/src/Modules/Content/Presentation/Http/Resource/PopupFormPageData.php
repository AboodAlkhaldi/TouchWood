<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W6's form (content.md §1.10, §4.4): a new pop-up in the store, or one that exists, with its image as its box shows it. */
#[TypeScript]
final class PopupFormPageData extends Data
{
    public function __construct(
        public ContentStoreData $store,
        public string $storeTimezone,
        public ?PopupData $popup,
        public ?ContentImageData $image,
        public bool $translationReady,
    ) {}
}
