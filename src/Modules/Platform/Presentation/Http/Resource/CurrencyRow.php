<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One currency (frontend.md 3.5, E3).
 */
#[TypeScript]
final class CurrencyRow extends Data
{
    public function __construct(
        /** ISO 4217, and the currency's name to the system. Never changes. */
        public string $code,
        /** Already in the language the panel is being read in. */
        public string $name,
        public string $nameAr,
        public string $nameEn,
        public string $abbreviationAr,
        public string $abbreviationEn,
        /** What a price shows next to its amount; the abbreviation when there is none. */
        public ?string $sign,
        public int $exponent,
        public int $storeCount,
        /** Settled the moment a store charges in it, and shown as settled (platform.md 1.2). */
        public bool $exponentLocked,
    ) {}
}
