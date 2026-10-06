<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Shared\Domain\Text\LatinDigits;

/**
 * A variant's code — the SKU, the provider's internal reference, the import's key (catalog.md §1.2):
 * **digits only, 1 to 10 of them** (owner, 2026-10-03, amendment 3(e), (j)), kept as text so a
 * leading zero would be kept exactly; Arabic digits typed are read as 0-9 (amendment 12). **It
 * belongs to one product**: its variants may share it (the same drawer in 60, 80 and 90 cm, as the
 * provider holds them); which product holds a code is a question about the other rows, so the
 * repository answers it (`CodeTaken`).
 */
final readonly class ProductCode
{
    public const int MAX_DIGITS = 10;

    private function __construct(
        public string $value,
    ) {}

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function of(string $code): self
    {
        $code = LatinDigits::of(CatalogText::trimmed($code));

        if (preg_match('/\A[0-9]{1,'.self::MAX_DIGITS.'}\z/', $code) !== 1) {
            throw new InvalidCatalogAttribute('code', 'digits only, 1 to '.self::MAX_DIGITS.' of them');
        }

        return new self($code);
    }

    public static function reconstitute(string $code): self
    {
        return new self($code);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
