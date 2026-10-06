<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * A product's two slugs (catalog.md §1.1): each typed, or — left empty — made from that language's
 * name (§9.3 #3, amendment 2(e)). **The English one exists exactly while the English name does**: a
 * draft named in Arabic only has no English address yet (amendment 3(g)).
 */
final readonly class ProductSlugs
{
    private function __construct(
        public Slug $ar,
        public ?Slug $en,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function for(ProductName $name, ?string $ar = null, ?string $en = null): self
    {
        $typedEn = $en === null || CatalogText::trimmed($en) === '' ? null : $en;

        if ($name->en === null && $typedEn !== null) {
            throw new InvalidCatalogAttribute('slug_en', 'the English name first');
        }

        return new self(
            self::one('ar', $ar, $name->ar),
            $name->en === null ? null : self::one('en', $typedEn, $name->en),
        );
    }

    public static function reconstitute(string $ar, ?string $en): self
    {
        return new self(Slug::reconstitute('ar', $ar), $en === null ? null : Slug::reconstitute('en', $en));
    }

    /**
     * @return array{ar: string, en: string|null} locale => slug, the English one null while there is
     *                                            no English name
     */
    public function byLocale(): array
    {
        return ['ar' => $this->ar->value, 'en' => $this->en?->value];
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function one(string $locale, ?string $typed, string $name): Slug
    {
        return $typed === null || CatalogText::trimmed($typed) === '' ? Slug::fromName($locale, $name) : Slug::of($locale, $typed);
    }
}
