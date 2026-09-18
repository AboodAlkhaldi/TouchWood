<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

final readonly class CurrencyDto
{
    public function __construct(
        public string $code,
        public int $exponent,
        public TranslatedTextDto $name,
        public TranslatedTextDto $abbreviation,
        public ?string $sign,
    ) {}

    /**
     * What a price shows next to its amount: the sign, or the letters when there is no sign.
     */
    public function displaySymbol(string $locale): string
    {
        return $this->sign ?? $this->abbreviation->in($locale);
    }
}
