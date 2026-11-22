<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Adding or renaming a type to a name another type of its kind in the same store already has, in
 * either language, ignoring case (b2b.md §1.3, §7, amendments 2 and 5): a dropdown never offers two
 * identical choices. Decided under the store's type-list lock, so two admins cannot both win.
 */
final class TypeNameTaken extends B2BError
{
    public function __construct()
    {
        parent::__construct('Another type in this store\'s list already has that name.');
    }

    public function type(): string
    {
        return 'b2b.type_name_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
