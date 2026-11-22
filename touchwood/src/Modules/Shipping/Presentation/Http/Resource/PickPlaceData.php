<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A row of the price dialog's place picker: one level's place, in both languages (R3, R4). */
#[TypeScript]
final class PickPlaceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $otherName,
    ) {}
}
