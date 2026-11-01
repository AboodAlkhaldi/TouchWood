<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Modules\Payments\Public\Enums\PaymentMode;

/**
 * Our own addresses, as the gateway needs them (payments.md §1.2).
 */
interface OwnAddresses
{
    /** The domain the shop answers on, as Apple Pay registers it. */
    public function domain(): string;

    /** A store's and mode's notice address: sent with every charge, and pasted into MyFatoorah's portal. */
    public function noticeUrl(string $storeId, PaymentMode $mode): string;
}
