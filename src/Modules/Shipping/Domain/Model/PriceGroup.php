<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Model;

/**
 * One amount of one carrier and the places it applies to (shipping.md §1.4): a region, a city or a
 * district each. A place is in one group of a carrier at most (rule 3), and two groups of a carrier
 * never share an amount (rule 4) - both held by the handler under the store's carriers' lock, and by
 * the tables' unique keys behind it.
 */
final class PriceGroup
{
    /**
     * @param  list<string>  $placeIds
     */
    private function __construct(
        public readonly string $id,
        public readonly string $carrierId,
        public readonly string $storeId,
        private int $amountMinor,
        private array $placeIds,
    ) {}

    /**
     * @param  list<string>  $placeIds
     */
    public static function of(string $id, string $carrierId, string $storeId, int $amountMinor, array $placeIds): self
    {
        return new self($id, $carrierId, $storeId, $amountMinor, self::sorted($placeIds));
    }

    /**
     * The version of a carrier's prices: changes whenever any group's amount or places change, so a page
     * that read an older one is told to reload (§1.4, §8 #3).
     *
     * @param  list<self>  $groups
     */
    public static function versionOf(array $groups): string
    {
        $lines = array_map(static fn (self $group): string => $group->amountMinor.':'.implode(',', $group->placeIds), $groups);
        sort($lines);

        return sha1(implode("\n", $lines));
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    /**
     * @return list<string>
     */
    public function placeIds(): array
    {
        return $this->placeIds;
    }

    public function has(string $placeId): bool
    {
        return in_array($placeId, $this->placeIds, true);
    }

    public function isEmpty(): bool
    {
        return $this->placeIds === [];
    }

    public function changeAmount(int $amountMinor): void
    {
        $this->amountMinor = $amountMinor;
    }

    /**
     * @param  list<string>  $placeIds
     */
    public function setPlaces(array $placeIds): void
    {
        $this->placeIds = self::sorted($placeIds);
    }

    public function remove(string $placeId): void
    {
        $this->placeIds = array_values(array_filter($this->placeIds, static fn (string $id): bool => $id !== $placeId));
    }

    /**
     * @param  list<string>  $placeIds
     * @return list<string>
     */
    private static function sorted(array $placeIds): array
    {
        $ids = array_values(array_unique($placeIds));
        sort($ids);

        return $ids;
    }
}
