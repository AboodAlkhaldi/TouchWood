<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the account's saved addresses the company form offers (b2b.md §4.5, amendment 16(f)).
 */
#[TypeScript]
final class CompanySavedAddressData extends Data
{
    public function __construct(
        public string $id,
        public string $storeNameAr,
        public string $storeNameEn,
        public string $label,
        /** As its store's format writes it: what the company keeps a copy of. */
        public string $formatted,
        /** False when its store's format no longer accepts it: shown, not offered. */
        public bool $isComplete,
    ) {}
}
