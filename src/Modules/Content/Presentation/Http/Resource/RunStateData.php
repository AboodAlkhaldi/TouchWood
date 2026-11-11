<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store's state of a campaign or a theme on its list (content.md W4, W5: "codes with each state").
 */
#[TypeScript]
final class RunStateData extends Data
{
    public function __construct(
        public string $storeId,
        public string $code,
        public string $state,
    ) {}
}
