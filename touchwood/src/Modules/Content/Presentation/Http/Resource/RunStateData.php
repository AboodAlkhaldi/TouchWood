<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store's state of a campaign or a theme on its list (content.md W4, W5: "codes with each state"), and for a
 * campaign's run how many sizes it prices there (W4's Offers; null while Pricing holds none - a draft, one ended).
 */
#[TypeScript]
final class RunStateData extends Data
{
    public function __construct(
        public string $storeId,
        public string $code,
        public string $state,
        public ?int $offers = null,
    ) {}
}
