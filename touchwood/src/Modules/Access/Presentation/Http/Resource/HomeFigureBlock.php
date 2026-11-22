<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One number on a home card, its words already in the reader's language (frontend.md §2.2).
 */
#[TypeScript]
final class HomeFigureBlock extends Data
{
    public function __construct(
        public string $label,
        public int $value,
        /** `count` or `bytes`: the page writes a size as a size, in Latin digits. */
        public string $unit,
        public ?string $href,
        public ?string $tone,
    ) {}
}
