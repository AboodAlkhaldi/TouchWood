<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A region or a city above a level's page: a step of its trail.
 */
#[TypeScript]
final class PlaceCrumb extends Data
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        /** Retired as the pickers see it: itself, or something above it. */
        public bool $retired,
    ) {}
}
