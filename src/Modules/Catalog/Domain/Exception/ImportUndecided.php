<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Bringing an import's products in while a name the catalog lacks, a code it already has, a web
 * address that would collide, a product on sale's keep or take off, or a code a product not a draft
 * keeps still waits for the Super Admin (catalog.md §1.12, amendments 6, 8(c), 9(c), 11(b)): how many
 * of each wait is in the context.
 */
final class ImportUndecided extends CatalogError
{
    public function __construct(
        public readonly int $names,
        public readonly int $codes,
        public readonly int $addresses = 0,
        public readonly int $sales = 0,
        public readonly int $codeChanges = 0,
        public readonly int $attributeChanges = 0,
    ) {
        parent::__construct("The import still waits for decisions: {$names} name(s), {$codes} code(s), {$addresses} address(es), {$sales} sale(s), {$codeChanges} code change(s), {$attributeChanges} attribute change(s).");
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
        return ['names' => $this->names, 'codes' => $this->codes, 'addresses' => $this->addresses, 'sales' => $this->sales, 'code_changes' => $this->codeChanges, 'attribute_changes' => $this->attributeChanges];
    }
}
