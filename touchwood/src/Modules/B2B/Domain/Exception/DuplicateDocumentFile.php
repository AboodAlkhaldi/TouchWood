<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A paper whose file is named exactly as a file already under another document type of the same
 * draft (b2b.md §4.5, §7, amendment 16(c)): one scanned file put in two sections is a mistake. The
 * name alone is compared, as the person's browser gave it; answers to a rejection's requests are not.
 */
final class DuplicateDocumentFile extends B2BError
{
    public function __construct()
    {
        parent::__construct('A file with this name is already under another document type of the draft.');
    }

    public function type(): string
    {
        return 'b2b.duplicate_document_file';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
