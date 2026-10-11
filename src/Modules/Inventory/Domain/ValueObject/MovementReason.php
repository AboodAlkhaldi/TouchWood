<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * Why in stock changed (inventory.md §1.5, §1.6, §5): a stocktake or one of the four hand reasons, a
 * shipment, a return, the store's file, the provider's number. Stored as its string.
 */
enum MovementReason: string
{
    case Stocktake = 'STOCKTAKE';
    case Received = 'RECEIVED';
    case DamagedLost = 'DAMAGED_LOST';
    case OfflineSale = 'OFFLINE_SALE';
    case Correction = 'CORRECTION';
    case Shipped = 'SHIPPED';
    case Returned = 'RETURNED';
    case File = 'FILE';
    case Provider = 'PROVIDER';
}
