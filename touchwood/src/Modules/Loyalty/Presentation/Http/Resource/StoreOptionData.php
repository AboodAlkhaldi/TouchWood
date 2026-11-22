<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A store in a Points screen's own store filter (frontend.md §2.2): an off one marked. */
#[TypeScript]
final class StoreOptionData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive,
    ) {}
}
