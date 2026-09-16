<?php

namespace Modules\Platform\Application\Command\CreateCurrency;

final readonly class CreateCurrency
{
    public function __construct(
        public string $code,
        public int $exponent,
        public string $nameAr,
        public string $nameEn,
        public string $abbreviationAr,
        public string $abbreviationEn,
        public ?string $sign,
    ) {}
}
