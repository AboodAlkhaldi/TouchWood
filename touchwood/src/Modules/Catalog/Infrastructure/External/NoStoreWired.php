<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\External;

use Modules\Catalog\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\StoreId;

/**
 * No store is wired until a module above Catalog says otherwise (Sync binds its own answer, catalog.md
 * amendment 43): every store's file then gives its prices and stock, as before Odoo.
 */
final readonly class NoStoreWired implements StoreWiring
{
    public function isWired(StoreId $store): bool
    {
        return false;
    }
}
