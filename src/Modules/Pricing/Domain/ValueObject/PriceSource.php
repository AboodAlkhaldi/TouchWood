<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

/**
 * Where a retail price came from (pricing.md §5): staff in the panel, the store's file, or the
 * provider in a wired store. Stored as its string, as every stored kind is (§5).
 */
enum PriceSource: string
{
    case Staff = 'STAFF';
    case File = 'FILE';
    case Provider = 'PROVIDER';
}
