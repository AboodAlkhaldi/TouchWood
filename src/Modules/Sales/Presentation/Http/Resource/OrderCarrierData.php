<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of the store's carriers for Ship… (sales.md §9.5 #3; gap 6.3): the order's country first, switched-on first; whether its pattern makes a Track link from the number. */
#[TypeScript]
final class OrderCarrierData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $countryCode,
        public bool $active,
        public bool $hasTrackingPattern,
    ) {}
}
