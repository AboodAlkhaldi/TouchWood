<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Repository;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\GiftLevel;
use Modules\Promotions\Domain\Model\GiftLevelTerms;
use Modules\Promotions\Public\Enums\Audience;
use Shared\Domain\ValueObject\StoreId;

/** A store's gift levels (promotions.md §1.7, §1.8): every read names its store. */
interface GiftLevels
{
    public function storeOf(string $levelId): ?StoreId;

    /**
     * Every level of the store, highest amount first (a store has few).
     *
     * @return list<GiftLevel>
     */
    public function ofStore(StoreId $store): array;

    /** The level, its row locked until the transaction ends. */
    public function lock(StoreId $store, string $levelId): ?GiftLevel;

    /**
     * The store's active levels with this amount, but the one named — those a level could clash with.
     *
     * @return list<GiftLevel>
     */
    public function activeWithAmount(StoreId $store, int $amountMinor, ?string $exceptId): array;

    public function create(string $levelId, StoreId $store, GiftLevelTerms $terms, bool $active, DateTimeImmutable $at): void;

    public function change(GiftLevel $level, GiftLevelTerms $terms, DateTimeImmutable $at): void;

    public function switchTo(GiftLevel $level, bool $active, DateTimeImmutable $at): void;

    /**
     * The levels an order of `$goodsMinor` reaches now, for this buyer — active, within their dates,
     * their amount at most the goods — highest amount first (§1.7).
     *
     * @return list<GiftLevel>
     */
    public function reached(StoreId $store, Audience $audience, int $goodsMinor, DateTimeImmutable $now): array;

    /**
     * The gift levels naming any of these variants (Catalog's usage check).
     *
     * @param  list<string>  $variantIds
     * @return list<GiftLevel>
     */
    public function namingVariants(array $variantIds): array;
}
