<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One part of a home split (dashboard.md §2 D, I): its words, its value, its share of the whole, and - where the
 * section shows it - its change against the period before.
 */
#[TypeScript]
final readonly class HomeSplitPart
{
    public function __construct(
        /** Already in the reader's language: a category's name, "Companies", "Online". */
        public string $label,
        public HomeValue $value,
        /** Its share of the whole in percent, to one decimal. */
        public float $share,
        public ?HomeChange $change = null,
    ) {}
}
