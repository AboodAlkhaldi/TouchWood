<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An address country in use, as the Stores page's Add Country offers it (owner, 2026-10-29, Q14):
 * one another store ships to, or one with no place list, listed out of reach with its reason.
 */
#[TypeScript]
final class ShipToChoice extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $hasPlaces,
        public ?PlaceStoreRow $store,
    ) {}
}
