<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * An attribute as the attributes screen reads it (catalog.md §1.7, §4.4 S3): what locks its job —
 * values, or details variants carry (amendments 1(i), 3(k)) — whether products make their variants of it (amendment 16(b)), and
 * whether anything uses it, for Delete.
 */
final readonly class AttributeRow
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public string $kind,
        public ?string $unitAr,
        public ?string $unitEn,
        public bool $isColour,
        public bool $active,
        public int $position,
        public int $values,
        public bool $kindLocked,
        public bool $inProducts,
        public bool $inUse,
    ) {}
}
