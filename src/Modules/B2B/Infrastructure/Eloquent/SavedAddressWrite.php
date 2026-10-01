<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Illuminate\Database\QueryException;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * A write that names a saved address (b2b.md §5.1, amendment 17(h)). The pick read the address a
 * moment before, but nothing held it, so Access may delete it in between; its foreign key then
 * refuses the write, and the person is told the address is not one of theirs any more rather than
 * shown an error page.
 */
final class SavedAddressWrite
{
    /** PostgreSQL's foreign key violation. */
    private const string FOREIGN_KEY_VIOLATION = '23503';

    /**
     * @param  callable(): mixed  $write
     *
     * @throws InvalidCompanyAttribute
     * @throws QueryException any other refusal of the write, as it came — a unique index's included
     */
    public static function guard(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if ((string) $e->getCode() === self::FOREIGN_KEY_VIOLATION && str_contains($e->getMessage(), '_address_id_foreign')) {
                throw new InvalidCompanyAttribute('address', 'not one of the account\'s saved addresses');
            }

            throw $e;
        }
    }
}
