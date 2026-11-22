<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * A product's name (catalog.md §1.1): one line in each language, at most 200 characters. **A draft
 * may have its Arabic name only**; the English name is required to be made ready (amendment 3(g):
 * "all products must have English names").
 */
final readonly class ProductName
{
    public const int MAX = 200;

    private function __construct(
        public string $ar,
        public ?string $en,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $ar, ?string $en): self
    {
        return new self(CatalogText::oneLine('name_ar', $ar, self::MAX), CatalogText::optionalLine('name_en', $en, self::MAX));
    }

    public static function reconstitute(string $ar, ?string $en): self
    {
        return new self($ar, $en);
    }

    public function hasEnglish(): bool
    {
        return $this->en !== null;
    }
}
