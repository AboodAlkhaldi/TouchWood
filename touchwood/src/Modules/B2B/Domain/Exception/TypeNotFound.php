<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A staff action on a company or document type that does not exist, or that belongs to a store the
 * staff member does not cover (b2b.md §3.2, §7, amendment 10(k)). The same answer for both, so the
 * panel never confirms that another store's type is there — as `CompanyNotFound` does for a company.
 */
final class TypeNotFound extends B2BError
{
    public function __construct(public readonly string $typeId = '')
    {
        parent::__construct("No type \"{$typeId}\".");
    }

    public function type(): string
    {
        return 'b2b.type_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
