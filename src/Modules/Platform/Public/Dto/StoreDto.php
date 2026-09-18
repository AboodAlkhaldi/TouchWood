<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Shared\Domain\ValueObject\StoreId;

/**
 * Carries the store's currency details too, so a price can be formatted with one call.
 */
final readonly class StoreDto
{
    public function __construct(
        public string $id,
        public string $code,
        public TranslatedTextDto $name,
        public string $countryCode,
        public string $currencyCode,
        public int $currencyExponent,
        public ?string $currencySign,
        public TranslatedTextDto $currencyAbbreviation,
        public int $taxRateBasisPoints,
        public string $timezone,
        public int $position,
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
