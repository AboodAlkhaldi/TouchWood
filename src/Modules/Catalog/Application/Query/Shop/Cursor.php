<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * Where a page of cards starts (handoff §5.4: keyset, no offset): the last card's sales rank — none
 * until Sales pushes one (§2.2) — and its product id, which breaks ties, newest first. Sent back by
 * the shopper's browser as text, so it is read as strictly as any other input.
 */
final readonly class Cursor
{
    private const string PATTERN = '/\A(-|0|[1-9][0-9]{0,9})\.([0-7][0-9a-hjkmnp-tv-z]{25})\z/';

    private function __construct(
        public ?int $salesRank,
        public string $productId,
    ) {}

    public static function after(?int $salesRank, string $productId): self
    {
        return new self($salesRank, strtolower($productId));
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function parse(?string $text): ?self
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (preg_match(self::PATTERN, strtolower($text), $match) !== 1 || ($match[1] !== '-' && (int) $match[1] > 2_147_483_647)) {
            throw new InvalidCatalogAttribute('after', 'a page cursor');
        }

        return new self($match[1] === '-' ? null : (int) $match[1], $match[2]);
    }

    public function text(): string
    {
        return ($this->salesRank === null ? '-' : (string) $this->salesRank).'.'.$this->productId;
    }
}
