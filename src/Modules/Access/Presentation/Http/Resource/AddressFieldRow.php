<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One field the store asks an address for (frontend.md §3.6, F9).
 *
 * The label is already in the page's language: which language that is belongs to the address of
 * the page, and the form has no business choosing between two.
 */
#[TypeScript]
final class AddressFieldRow extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public int $maxLength,
    ) {}
}
