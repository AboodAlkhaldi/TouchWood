<?php

declare(strict_types=1);

namespace Shared\Application;

use LogicException;

/**
 * A store-scoped row was about to be written for a store other than the current one. Always a
 * programming error — never shown to a customer and never a DomainError.
 */
final class CrossStoreWrite extends LogicException
{
    public static function create(string $table, string $rowStore, string $currentStore): self
    {
        return new self("Refusing to write a {$table} row for store {$rowStore} while the current store is {$currentStore}.");
    }

    public static function move(string $table): self
    {
        return new self("Refusing to change store_id on a {$table} row: a row never moves between stores.");
    }
}
