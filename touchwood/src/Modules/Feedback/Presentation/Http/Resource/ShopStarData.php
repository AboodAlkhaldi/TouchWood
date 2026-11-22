<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One bar of the star breakdown: a star, 5 to 1, and its approved reviews (feedback.md §2.4 B1). */
#[TypeScript]
final class ShopStarData extends Data
{
    public function __construct(
        public int $star,
        public int $count,
    ) {}
}
