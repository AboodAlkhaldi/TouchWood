<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

/** An online way to pay a gateway offers (sales.md §2.4): its code and its names. Provisional. */
final readonly class PaymentMethodDto
{
    public function __construct(
        public string $code,
        public string $nameAr,
        public string $nameEn,
    ) {}
}
