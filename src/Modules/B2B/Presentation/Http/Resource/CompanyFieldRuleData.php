<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What one field of the company form accepts (b2b.md §4.5, amendment 16(a)): the same numbers the
 * server holds it to, so the page never sends a value the server would refuse for its length.
 */
#[TypeScript]
final class CompanyFieldRuleData extends Data
{
    public function __construct(
        /** Today's minimum, in characters, spaces at either end not counted (amendment 16(b)). */
        public int $min,
        public int $max,
        /** No line breaks. */
        public bool $oneLine,
        /** A regular expression every value must match, or null for any characters. */
        public ?string $characters,
    ) {}
}
