<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

/**
 * A name the file's products use that the catalog lacks — or that several catalog items answer to,
 * `$matches` of them (amendment 8(d)) — and the Super Admin's decision (§1.12, part 1); a new
 * category whose address another took since it was decided says so (`$addressTaken`).
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
        public int $matches,
        public ?string $slugAr,
        public ?string $slugEn,
        public bool $addressTaken,
    ) {}
}
