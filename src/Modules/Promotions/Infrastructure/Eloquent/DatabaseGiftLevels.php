<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Promotions\Domain\Model\GiftLevel;
use Modules\Promotions\Domain\Model\GiftLevelTerms;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Modules\Promotions\Public\Enums\Audience;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/** `GiftLevels` in SQL, no Eloquent model (§1.8). */
final readonly class DatabaseGiftLevels implements GiftLevels
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function storeOf(string $levelId): ?StoreId
    {
        $store = Ulids::valid($levelId) ? $this->db->table('promotions.gift_levels')->where('id', strtolower($levelId))->value('store_id') : null;

        return $store === null ? null : StoreId::fromString((string) $store);
    }

    public function ofStore(StoreId $store): array
    {
        return array_values(array_filter(array_map($this->toLevel(...), $this->levels($store)->orderByDesc('amount_minor')->orderBy('id')->get()->all())));
    }

    public function lock(StoreId $store, string $levelId): ?GiftLevel
    {
        return Ulids::valid($levelId) ? $this->toLevel($this->levels($store)->where('id', strtolower($levelId))->lockForUpdate()->first()) : null;
    }

    public function activeWithAmount(StoreId $store, int $amountMinor, ?string $exceptId): array
    {
        return array_values(array_filter(array_map($this->toLevel(...), $this->levels($store)
            ->where('active', true)
            ->where('amount_minor', $amountMinor)
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '<>', $exceptId))
            ->orderBy('id')
            ->get()
            ->all())));
    }

    public function create(string $levelId, StoreId $store, GiftLevelTerms $terms, bool $active, DateTimeImmutable $at): void
    {
        $this->db->table('promotions.gift_levels')->insert([
            'id' => $levelId,
            'store_id' => $store->value,
            ...self::termsRow($terms),
            'active' => $active,
            'created_at' => Moment::of($at),
            'updated_at' => Moment::of($at),
        ]);
    }

    public function change(GiftLevel $level, GiftLevelTerms $terms, DateTimeImmutable $at): void
    {
        $this->levels(StoreId::fromString($level->storeId))->where('id', $level->id)->update([...self::termsRow($terms), 'updated_at' => Moment::of($at)]);
    }

    public function switchTo(GiftLevel $level, bool $active, DateTimeImmutable $at): void
    {
        $this->levels(StoreId::fromString($level->storeId))->where('id', $level->id)->update(['active' => $active, 'updated_at' => Moment::of($at)]);
    }

    public function reached(StoreId $store, Audience $audience, int $goodsMinor, DateTimeImmutable $now): array
    {
        $at = Moment::of($now);

        return array_values(array_filter(array_map($this->toLevel(...), $this->levels($store)
            ->where('active', true)
            ->where('amount_minor', '<=', $goodsMinor)
            ->where($audience === Audience::Company ? 'for_company' : 'for_public', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderByDesc('amount_minor')
            ->orderBy('id')
            ->get()
            ->all())));
    }

    public function namingVariants(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return array_values(array_filter(array_map($this->toLevel(...), $this->db->table('promotions.gift_levels')->whereIn('variant_id', $variantIds)->orderBy('id')->get()->all())));
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private static function termsRow(GiftLevelTerms $terms): array
    {
        return [
            'amount_minor' => $terms->amountMinor,
            'currency_code' => $terms->currencyCode,
            'variant_id' => $terms->variantId,
            'for_public' => $terms->forPublic,
            'for_company' => $terms->forCompany,
            'starts_at' => $terms->startsAt === null ? null : Moment::of($terms->startsAt),
            'ends_at' => $terms->endsAt === null ? null : Moment::of($terms->endsAt),
        ];
    }

    private function levels(StoreId $store): Builder
    {
        return $this->db->table('promotions.gift_levels')->where('store_id', $store->value);
    }

    private function toLevel(mixed $row): ?GiftLevel
    {
        if (! $row instanceof stdClass) {
            return null;
        }

        return new GiftLevel(
            (string) $row->id,
            (string) $row->store_id,
            (int) $row->amount_minor,
            (string) $row->currency_code,
            (string) $row->variant_id,
            (bool) $row->for_public,
            (bool) $row->for_company,
            $row->starts_at === null ? null : CarbonImmutable::parse((string) $row->starts_at),
            $row->ends_at === null ? null : CarbonImmutable::parse((string) $row->ends_at),
            (bool) $row->active,
        );
    }
}
