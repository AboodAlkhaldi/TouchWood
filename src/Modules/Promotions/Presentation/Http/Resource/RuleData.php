<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of a coupon's Includes or Excludes (promotions.md §1.2, §4.1 M2): `kind` INCLUDE or EXCLUDE,
 * `target` product, category or brand; named in the page's language, null where Catalog no longer has
 * it. `path` a category's whole path, root first ("Kitchens › Drawers", owner's Q4); `sold` false for an
 * entry archived, switched off or gone - it stays on the list, matching nothing (answer 22).
 */
#[TypeScript]
final class RuleData extends Data
{
    public function __construct(
        public string $kind,
        public string $target,
        public string $id,
        public ?string $name,
        public ?string $path,
        public bool $sold,
    ) {}
}
