<?php

namespace Modules\Platform\Public\Dto;

use Spatie\LaravelData\Data;

final class CurrencyDto extends Data
{
    public function __construct(
        public readonly string $code,
        public readonly int $exponent,
        public readonly TranslatedTextDto $name,
        public readonly TranslatedTextDto $abbreviation,
        public readonly ?string $sign,
    ) {}

    /**
     * What a price shows next to its amount: the sign, or the letters when there is no sign.
     */
    public function displaySymbol(string $locale): string
    {
        return $this->sign ?? $this->abbreviation->in($locale);
    }
}
