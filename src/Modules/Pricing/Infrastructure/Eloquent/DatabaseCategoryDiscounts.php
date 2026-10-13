<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Domain\ValueObject\DiscountLines;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `pricing.category_discounts` and `pricing.category_discount_exclusions` (pricing.md §5).
 */
final readonly class DatabaseCategoryDiscounts implements CategoryDiscounts
{
    private const string TABLE = 'pricing.category_discounts';

    private const string EXCLUSIONS = 'pricing.category_discount_exclusions';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $discountId): ?CategoryDiscount
    {
        $row = $this->db->table(self::TABLE)->where('id', strtolower(trim($discountId)))->first();

        return $row instanceof stdClass ? $this->discounts([$row])[0] : null;
    }

    public function save(CategoryDiscount $discount): void
    {
        $this->db->table(self::TABLE)->upsert([[
            'id' => $discount->id,
            'store_id' => $discount->storeId,
            'category_id' => $discount->categoryId,
            'percent_bp' => $discount->percentBasisPoints,
            'amount_minor' => $discount->amount?->minorUnits,
            'currency_code' => $discount->amount?->currencyCode,
            'lines' => $discount->lines->value,
            'starts_at' => $discount->startsAt->format('Y-m-d H:i:s.uP'),
            'ends_at' => $discount->endsAt?->format('Y-m-d H:i:s.uP'),
            'always_wins' => $discount->alwaysWins,
            'created_at' => CarbonImmutable::now(),
        ]], ['id'], ['category_id', 'percent_bp', 'amount_minor', 'currency_code', 'lines', 'starts_at', 'ends_at', 'always_wins']);

        $this->db->table(self::EXCLUSIONS)->where('discount_id', $discount->id)->delete();
        $this->db->table(self::EXCLUSIONS)->insert(array_map(
            static fn (string $productId): array => ['discount_id' => $discount->id, 'product_id' => $productId],
            $discount->excludedProductIds,
        ));
    }

    public function remove(string $discountId): void
    {
        $this->db->table(self::TABLE)->where('id', $discountId)->delete();
    }

    public function notEnded(StoreId $store, DateTimeImmutable $at): array
    {
        return $this->discounts($this->db->table(self::TABLE.' as d')
            ->where('d.store_id', $store->value)
            ->where(fn (Builder $query) => $query->whereNull('d.ends_at')->orWhere('d.ends_at', '>', $at->format('Y-m-d H:i:s.uP')))
            ->orderBy('d.starts_at')->orderBy('d.id')
            ->select('d.*')
            ->get()->values()->all());
    }

    public function storesWithDiscounts(DateTimeImmutable $at): array
    {
        return array_values(array_map('strval', $this->db->table(self::TABLE)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at->format('Y-m-d H:i:s.uP')))
            ->distinct()->orderBy('store_id')->pluck('store_id')->all()));
    }

    public function windowsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$after, $by] = [$from->format('Y-m-d H:i:s.uP'), $to->format('Y-m-d H:i:s.uP')];

        return $this->discounts($this->db->table(self::TABLE.' as d')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $start) => $start->where('d.starts_at', '>', $after)->where('d.starts_at', '<=', $by))
                ->orWhere(fn (Builder $end) => $end->where('d.ends_at', '>', $after)->where('d.ends_at', '<=', $by)))
            ->orderBy('d.store_id')->orderBy('d.id')
            ->select('d.*')
            ->get()->values()->all());
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<CategoryDiscount>
     */
    private function discounts(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));

        if ($rows === []) {
            return [];
        }

        $excluded = [];

        foreach ($this->db->table(self::EXCLUSIONS)->whereIn('discount_id', array_map(static fn (stdClass $row): string => (string) $row->id, $rows))->orderBy('product_id')->get() as $exclusion) {
            $excluded[(string) $exclusion->discount_id][] = (string) $exclusion->product_id;
        }

        return array_map(static fn (stdClass $row): CategoryDiscount => new CategoryDiscount(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->category_id,
            $row->percent_bp === null ? null : (int) $row->percent_bp,
            $row->amount_minor === null ? null : Money::of((int) $row->amount_minor, (string) $row->currency_code),
            DiscountLines::from((string) $row->lines),
            CarbonImmutable::parse((string) $row->starts_at)->toDateTimeImmutable(),
            $row->ends_at === null ? null : CarbonImmutable::parse((string) $row->ends_at)->toDateTimeImmutable(),
            (bool) $row->always_wins,
            $excluded[(string) $row->id] ?? [],
        ), $rows);
    }
}
