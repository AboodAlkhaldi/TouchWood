<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

/**
 * Where a retail price came from (pricing.md §5): staff in the panel, the store's file, or the
 * provider in a wired store. Kept as strings, as every stored kind is (§5).
 */
final class PriceSource
{
    public const string STAFF = 'STAFF';

    public const string FILE = 'FILE';

    public const string PROVIDER = 'PROVIDER';
}
