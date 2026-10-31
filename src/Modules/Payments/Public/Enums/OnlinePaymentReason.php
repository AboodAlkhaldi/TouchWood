<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Enums;

/**
 * Why a store takes no online payment now (payments.md §2.1, §1.2): individuals are then offered
 * "staff will contact you" only.
 */
enum OnlinePaymentReason: string
{
    case NoGateway = 'NO_GATEWAY';
    case GatewayOff = 'GATEWAY_OFF';
    case KeyRejected = 'KEY_REJECTED';
    case Company = 'COMPANY';
}
