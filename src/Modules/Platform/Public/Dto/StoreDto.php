<?php

namespace Modules\Platform\Public\Dto;

use Shared\Domain\ValueObject\StoreId;
use Spatie\LaravelData\Data;

/**
 * Carries the store's currency details too, so a price can be formatted with one call.
 */
final class StoreDto extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly TranslatedTextDto $name,
        public readonly string $countryCode,
        public readonly string $currencyCode,
        public readonly int $currencyExponent,
        public readonly ?string $currencySign,
        public readonly TranslatedTextDto $currencyAbbreviation,
        public readonly int $taxRateBasisPoints,
        public readonly string $timezone,
        public readonly int $position,
    ) {}

    public function storeId(): StoreId
    {
        return StoreId::fromString($this->id);
    }

    public function currencySymbol(string $locale): string
    {
        return $this->currencySign ?? $this->currencyAbbreviation->in($locale);
    }
}
