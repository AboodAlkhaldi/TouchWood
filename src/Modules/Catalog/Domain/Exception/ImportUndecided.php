<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Bringing an import's products in while a name the catalog lacks, or a code it already has, still
 * waits for the Super Admin's decision (catalog.md §1.12, amendment 6): how many of each wait is in
 * the context.
 */
final class ImportUndecided extends CatalogError
{
    public function __construct(
        public readonly int $names,
        public readonly int $codes,
    ) {
        parent::__construct("The import still waits for decisions: {$names} name(s), {$codes} code(s).");
    }

    public function type(): string
    {
        return 'catalog.import_undecided';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['names' => $this->names, 'codes' => $this->codes];
    }
}
