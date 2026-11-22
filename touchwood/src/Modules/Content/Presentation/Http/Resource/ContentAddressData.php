<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Content's own copy of the shop page's address** (content.md §1.14: Catalog's `ShopAddressData` is in its
 * Presentation): the page's own address, the same page in the other language (the language switch's), whether search
 * engines are kept out, and, for a home, **every home it names** - each store that is on, in both languages, by
 * language and country (`ar-SA`, Q21 (a)). With `alternates` empty, the page names itself and its other language.
 */
#[TypeScript]
final class ContentAddressData extends Data
{
    /**
     * @param  list<ContentAlternateData>  $alternates
     */
    public function __construct(
        public string $canonical,
        public ?string $alternate,
        public ?string $alternateLocale,
        public bool $noindex,
        public array $alternates = [],
    ) {}
}
