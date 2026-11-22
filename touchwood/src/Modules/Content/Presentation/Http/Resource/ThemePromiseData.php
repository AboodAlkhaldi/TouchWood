<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One promise's words on a theme's promises row (content.md §1.8 part 3).
 */
#[TypeScript]
final class ThemePromiseData extends Data
{
    public function __construct(
        public string $ar,
        public ?string $en,
    ) {}
}
