<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Modules\Catalog\Domain\Exception\CatalogItemInUse;
use Modules\Catalog\Public\Enums\CatalogItem;

/**
 * The backstop behind Catalog's usage check (amendment 19), as Platform's media delete has one: the
 * delete runs in a savepoint, and a row another module wrote meanwhile — its foreign key, RESTRICT,
 * refusing — is `CatalogItemInUse`, naming the table, never a database error that aborts the caller's
 * whole transaction.
 */
final class ReferencedDelete
{
    private const string FOREIGN_KEY_VIOLATION = '23503';

    /**
     * @param  Closure(): mixed  $delete
     *
     * @throws CatalogItemInUse
     */
    public static function run(ConnectionInterface $db, CatalogItem $item, string $id, Closure $delete): void
    {
        try {
            $db->transaction($delete);
        } catch (QueryException $error) {
            if ($error->getCode() !== self::FOREIGN_KEY_VIOLATION) {
                throw $error;
            }

            $table = preg_match('/referenced from table "([^"]+)"/', $error->getMessage(), $match) === 1 ? $match[1] : 'unknown';

            throw new CatalogItemInUse([$table], 1, $item, strtolower($id));
        }
    }
}
