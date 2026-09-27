<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending an application without a paper every company is asked for: a document type that is both
 * required and still offered (b2b.md §1.3, §7). Nothing is created.
 *
 * It names the type by id, not by name: the domain does not know the reader's language, and the
 * form it came from already marks which field is empty.
 */
final class MissingRequiredDocument extends B2BError
{
    public function __construct(public readonly string $documentTypeId = '')
    {
        parent::__construct("The application is missing a required document ({$documentTypeId}).");
    }

    public function type(): string
    {
        return 'b2b.missing_required_document';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
