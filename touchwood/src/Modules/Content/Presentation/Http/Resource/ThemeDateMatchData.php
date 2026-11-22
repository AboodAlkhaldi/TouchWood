<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Whether a linked theme's moments in a store are the campaign run's there (content.md §1.7 #7, Q6 (a)).
 */
#[TypeScript]
final class ThemeDateMatchData extends Data
{
    public function __construct(
        public string $storeId,
        public bool $match,
    ) {}
}
