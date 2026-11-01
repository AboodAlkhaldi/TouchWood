<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\RegisterApplePayDomain;

/**
 * A Super Admin registers a domain for Apple Pay with MyFatoorah, per mode (payments.md §1.2, gap 8.5),
 * once MyFatoorah's file is served on it. Until then the form there shows no Apple Pay.
 */
final readonly class RegisterApplePayDomain
{
    public function __construct(
        public string $storeId,
        public string $mode,
        public string $domain,
    ) {}
}
