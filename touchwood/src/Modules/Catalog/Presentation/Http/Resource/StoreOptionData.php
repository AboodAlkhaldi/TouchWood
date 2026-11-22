<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store a screen's own store filter offers (frontend.md §2.2): its code, its name in the panel's
 * language, and whether it is on — a Super Admin's off stores are offered too, marked Off.
 */
#[TypeScript]
final class StoreOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $isActive,
    ) {}
}
