<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One version of a shop page another language or country reads (content.md §1.14): `hreflang` and its address. */
#[TypeScript]
final class ContentAlternateData extends Data
{
    public function __construct(
        public string $hrefLang,
        public string $href,
    ) {}
}
