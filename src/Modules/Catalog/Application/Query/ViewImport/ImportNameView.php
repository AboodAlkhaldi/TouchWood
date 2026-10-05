<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

/**
 * A name the file's products use that the catalog lacks, and the Super Admin's decision (§1.12, part 1).
 */
final readonly class ImportNameView
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $written,
        public ?string $attribute,
        public ?string $attributeKind,
        public ?string $decision,
        public ?string $targetId,
        public ?string $nameAr,
        public ?string $nameEn,
        public int $products,
    ) {}
}
