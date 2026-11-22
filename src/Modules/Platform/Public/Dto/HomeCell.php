<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One cell of a home table (dashboard.md §2 G, H): a value, or words - drawn as a badge when it has a tone (an
 * order's status).
 */
#[TypeScript]
final readonly class HomeCell
{
    public function __construct(
        public ?HomeValue $value = null,
        /** Already in the reader's language. */
        public ?string $text = null,
        /** A Geist tone (`amber-subtle`, `green-subtle`…) for words drawn as a badge. */
        public ?string $badge = null,
    ) {}
}
