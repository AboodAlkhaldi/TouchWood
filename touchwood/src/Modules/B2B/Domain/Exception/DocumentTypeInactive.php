<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Uploading under a document type that is inactive, of another store, or does not exist (b2b.md
 * §1.4, §7, amendment 4). Checked before anything is stored.
 */
final class DocumentTypeInactive extends B2BError
{
    public function __construct(public readonly string $documentTypeId = '')
    {
        parent::__construct("The document type \"{$documentTypeId}\" is not offered.");
    }

    public function type(): string
    {
        return 'b2b.document_type_inactive';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
