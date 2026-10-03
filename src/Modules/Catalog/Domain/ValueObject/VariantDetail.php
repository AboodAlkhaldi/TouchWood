<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * A variant's value for an attribute shown in the details only (catalog.md §1.7, §9.3 #13): **text
 * in both languages, or a number** read with the attribute's unit ("Material: oak", "Load: 25 kg").
 * The number is kept as the decimal it was given — at most nine digits before the point and three
 * after, as its column holds it — never as a float.
 */
final readonly class VariantDetail
{
    public const int TEXT_MAX = 200;

    private function __construct(
        public ?string $textAr,
        public ?string $textEn,
        public ?string $number,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function text(string $ar, string $en): self
    {
        return new self(CatalogText::oneLine('text_ar', $ar, self::TEXT_MAX), CatalogText::oneLine('text_en', $en, self::TEXT_MAX), null);
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function number(string $number): self
    {
        $number = CatalogText::trimmed($number);

        if (preg_match('/\A-?[0-9]{1,9}(\.[0-9]{1,3})?\z/', $number) !== 1) {
            throw new InvalidCatalogAttribute('number', 'a number with at most nine digits before the point and three after');
        }

        return new self(null, null, self::canonical($number));
    }

    public static function reconstitute(?string $textAr, ?string $textEn, ?string $number): self
    {
        return new self($textAr, $textEn, $number === null ? null : self::canonical($number));
    }

    public function equals(self $other): bool
    {
        return $this->textAr === $other->textAr && $this->textEn === $other->textEn && $this->number === $other->number;
    }

    /**
     * One way to write each number, so "25", "25.0" and "025" are one value and a read-back numeric
     * ("25.000") compares equal to what was typed.
     */
    private static function canonical(string $number): string
    {
        $negative = str_starts_with($number, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($number, '-'), 2), 2, '');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $text = $fraction === '' ? $whole : "{$whole}.{$fraction}";

        return $negative && $text !== '0' ? "-{$text}" : $text;
    }
}
