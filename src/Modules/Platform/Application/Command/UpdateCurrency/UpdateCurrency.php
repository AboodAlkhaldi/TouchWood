<?php

namespace Modules\Platform\Application\Command\UpdateCurrency;

/**
 * Only the given fields change. To remove the sign, so prices fall back to the abbreviation,
 * set $clearSign.
 */
final readonly class UpdateCurrency
{
    public function __construct(
        public string $code,
        public ?int $exponent = null,
        public ?string $nameAr = null,
        public ?string $nameEn = null,
        public ?string $abbreviationAr = null,
        public ?string $abbreviationEn = null,
        public ?string $sign = null,
        public bool $clearSign = false,
    ) {}
}
