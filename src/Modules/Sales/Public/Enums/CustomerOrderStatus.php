<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/**
 * The one status a customer sees of their order (sales.md §1.4; handoff §12.3): New · Processing · Shipped ·
 * Delivered · Cancelled · Refunded — worked out from the four machines staff see.
 */
enum CustomerOrderStatus: string
{
    case New = 'NEW';
    case Processing = 'PROCESSING';
    case Shipped = 'SHIPPED';
    case Delivered = 'DELIVERED';
    case Cancelled = 'CANCELLED';
    case Refunded = 'REFUNDED';
}
