<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The store has no address format yet (spec §1.9): a country opened before its scheme was entered.
 * Every store gets the standard one when the schema is migrated and when it is opened (amendment
 * 41), so this is only reached if a store's format was deliberately removed.
 */
final class AddressFormatMissing extends AccessError
{
    public function __construct(string $storeId)
    {
        parent::__construct("The store \"{$storeId}\" has no address format.");
    }

    public function type(): string
    {
        return 'access.address_format_missing';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
