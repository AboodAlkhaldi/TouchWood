<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One country in the account form's country list (frontend.md §3.2, B1).
 *
 * Staff can live anywhere (Access, CountryCode), so the list is every ISO country, named by ICU in
 * the language the panel is being read in. The names are not in the translation files: there are
 * some 250 of them in two languages, they are nobody's copy to write, and ICU already holds them.
 */
#[TypeScript]
final class CountryOption extends Data
{
    public function __construct(
        /** ISO 3166-1 alpha-2. */
        public string $code,
        public string $name,
        /**
         * Whether this is a country we have a store in.
         *
         * Those few come first in the list, because they are what a person picks nearly every time
         * and nobody should scroll past two hundred countries to reach one of three (owner,
         * 2026-09-24). Taken from the stores themselves rather than written down, so opening a
         * country puts it at the top without anybody remembering to.
         */
        public bool $ours = false,
    ) {}
}
