<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * A name in Arabic and in English — both required, as every name in this system is (handoff §5.2:
 * no fallback on a name). One line each; the longest each list allows is its own (catalog.md §9.3
 * #2: a product 200, a category or a brand 100, a label 30).
 */
final readonly class LocalizedName
{
    private function __construct(
        public string $ar,
        public string $en,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $ar, string $en, int $max): self
    {
        return new self(CatalogText::oneLine('name_ar', $ar, $max), CatalogText::oneLine('name_en', $en, $max));
    }

    /**
     * A name read back from the database, which already holds only what of() accepted.
     */
    public static function reconstitute(string $ar, string $en): self
    {
        return new self($ar, $en);
    }

    public function in(string $locale): string
    {
        return $locale === 'en' ? $this->en : $this->ar;
    }

    public function equals(self $other): bool
    {
        return $this->ar === $other->ar && $this->en === $other->en;
    }
}
