<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store the company list may be filtered by, named in the panel's language.
 */
#[TypeScript]
final class StaffStoreOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
