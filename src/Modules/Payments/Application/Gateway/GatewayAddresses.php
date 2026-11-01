<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Modules\Payments\Public\Enums\PaymentMode;

/**
 * The gateway's addresses, fixed per mode and - in live - per store country, never typed (payments.md
 * §1.2). Which countries the gateway is offered in is the adapter's to say (gap 8.1).
 */
interface GatewayAddresses
{
    public function offered(string $countryCode): bool;

    /** The API's base address for the store's country and the mode; only for a country it is offered in. */
    public function api(string $countryCode, PaymentMode $mode): string;

    /** Where the payment form's script is loaded from. */
    public function script(string $countryCode, PaymentMode $mode): string;
}
