<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * Where the next page of search results starts (P16; handoff §5.4: keyset, no offset): the last
 * result's place in the ranking — its tier, its nearness (only the nearest tier is ranked by it, in
 * hundred-thousandths), its sales rank, none until Sales pushes one — and its product id, which breaks
 * ties, newest first. Sent back by the browser, so read as strictly as any other input.
 */
final readonly class SearchCursor
{
    /** Where an unranked product sorts: after every rank Sales can push. */
    public const int UNRANKED = 2_147_483_648;

    private const string PATTERN = '/\A([1-5])\.(0|-[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,9})\.([0-7][0-9a-hjkmnp-tv-z]{25})\z/';

    private function __construct(
        public int $tier,
        public int $nearness,
        public int $rank,
        public string $productId,
    ) {}

    public static function after(int $tier, int $nearness, int $rank, string $productId): self
    {
        return new self($tier, $nearness, $rank, strtolower($productId));
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function parse(?string $text): ?self
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (preg_match(self::PATTERN, strtolower($text), $match) !== 1 || (int) $match[2] < -100_000 || (int) $match[3] > self::UNRANKED) {
            throw new InvalidCatalogAttribute('after', 'a page cursor');
        }

        return new self((int) $match[1], (int) $match[2], (int) $match[3], $match[4]);
    }

    public function text(): string
    {
        return "{$this->tier}.{$this->nearness}.{$this->rank}.{$this->productId}";
    }
}
