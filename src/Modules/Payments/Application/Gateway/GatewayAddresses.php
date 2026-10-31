<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Modules\Payments\Public\Enums\PaymentMode;

/**
 * MyFatoorah's addresses, fixed per mode and - in live - per country, never typed (payments.md §1.2;
 * MyFatoorah's live-token and embedded-payment-v3 pages, checked 2026-10-31). MyFatoorah is offered to
 * the Saudi and Egyptian stores (owner, 2026-10-29, gap 8.1); each country needs its own key.
 */
final class GatewayAddresses
{
    private const array LIVE = [
        'SA' => ['https://api-sa.myfatoorah.com', 'https://sa.myfatoorah.com'],
        'EG' => ['https://api-eg.myfatoorah.com', 'https://eg.myfatoorah.com'],
    ];

    private const array TEST = ['https://apitest.myfatoorah.com', 'https://demo.myfatoorah.com'];

    public static function offered(string $countryCode): bool
    {
        return isset(self::LIVE[strtoupper($countryCode)]);
    }

    /** The API's base address for the store's country and the mode. */
    public static function api(string $countryCode, PaymentMode $mode): string
    {
        return ($mode === PaymentMode::Test ? self::TEST : self::LIVE[strtoupper($countryCode)])[0];
    }

    /** Where the payment form's script is loaded from. */
    public static function script(string $countryCode, PaymentMode $mode): string
    {
        return ($mode === PaymentMode::Test ? self::TEST : self::LIVE[strtoupper($countryCode)])[1];
    }
}
