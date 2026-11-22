<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * **Whether a store is wired to Odoo** (catalog.md amendment 43; owner, 2026-11-11), as Pricing and
 * Inventory ask it: a wired store's prices and stock are Odoo's, so its store file carries codes only;
 * a store not wired gives them in its file. Sync answers it from its connections (it sits above
 * Catalog, so Catalog never reads it); until Sync binds it, no store is wired (`NoStoreWired`).
 */
interface StoreWiring
{
    public function isWired(StoreId $store): bool;
}
