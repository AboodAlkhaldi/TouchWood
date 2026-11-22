<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * The two slugs a product, a category or a brand has: one Arabic, one English, both required
 * (handoff §5.2: no fallback on a slug).
 */
final readonly class Slugs
{
    private function __construct(
        public Slug $ar,
        public Slug $en,
    ) {}

    /**
     * Each slug as typed, or — left empty — made from that language's name (catalog.md §9.3 #3).
     *
     * @throws InvalidCatalogAttribute
     */
    public static function for(LocalizedName $name, ?string $ar = null, ?string $en = null): self
    {
        return new self(
            self::one('ar', $ar, $name->ar),
            self::one('en', $en, $name->en),
        );
    }

    public static function reconstitute(string $ar, string $en): self
    {
        return new self(Slug::reconstitute('ar', $ar), Slug::reconstitute('en', $en));
    }

    public function in(string $locale): Slug
    {
        return $locale === 'en' ? $this->en : $this->ar;
    }

    public function equals(self $other): bool
    {
        return $this->ar->value === $other->ar->value && $this->en->value === $other->en->value;
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function one(string $locale, ?string $typed, string $name): Slug
    {
        return $typed === null || CatalogText::trimmed($typed) === '' ? Slug::fromName($locale, $name) : Slug::of($locale, $typed);
    }
}
