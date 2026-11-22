<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A home table's column heading (dashboard.md §2 G, H), already in the reader's language; a figure's column lines
 * up at the end.
 */
#[TypeScript]
final readonly class HomeColumn
{
    public function __construct(
        public string $label,
        public bool $end = false,
    ) {}
}
