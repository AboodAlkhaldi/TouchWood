<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of a theme's 11 parts, filled or left empty (content.md §1.8).
 */
#[TypeScript]
final class ThemePartFillData extends Data
{
    public function __construct(
        public string $part,
        public bool $filled,
    ) {}
}
