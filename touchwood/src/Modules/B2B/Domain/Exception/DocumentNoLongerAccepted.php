<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending a draft that still holds a file under a document type staff have deactivated since
 * (b2b.md §1.3, §7, amendment 5): the draft shows it "no longer accepted", and the file must be
 * taken out before the draft is sent. A company type deactivated since is CompanyTypeInactive.
 *
 * It names the type by id in getMessage() only: the domain does not know the reader's language,
 * and the draft already marks the file.
 */
final class DocumentNoLongerAccepted extends B2BError
{
    public function __construct(public readonly string $documentTypeId = '')
    {
        parent::__construct("The application holds a file under a document type no longer accepted ({$documentTypeId}).");
    }

    public function type(): string
    {
        return 'b2b.document_no_longer_accepted';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
