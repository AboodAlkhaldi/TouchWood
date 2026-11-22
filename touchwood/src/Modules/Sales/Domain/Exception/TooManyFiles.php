<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A fourth transfer document; a document while nothing is owed (§1.9).
 */
final class TooManyFiles extends SalesError
{
    public function __construct()
    {
        parent::__construct('No more documents can be added.');
    }

    public function type(): string
    {
        return 'sales.too_many_files';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
