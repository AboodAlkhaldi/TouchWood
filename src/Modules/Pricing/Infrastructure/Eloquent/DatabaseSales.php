<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Sale;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `pricing.sales` and `pricing.sale_variants` (pricing.md §5).
 */
final readonly class DatabaseSales implements Sales
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $saleId): ?Sale
    {
        $row = $this->db->table('pricing.sales')->where('id', strtolower(trim($saleId)))->first();

        return $row instanceof stdClass ? $this->sales([$row])[0] : null;
    }

    public function save(Sale $sale): void
    {
        $this->db->table('pricing.sales')->upsert([[
            'id' => $sale->id,
            'store_id' => $sale->storeId,
            'product_id' => $sale->productId,
            'percent_bp' => $sale->percentBasisPoints,
            'amount_minor' => $sale->price?->minorUnits,
            'currency_code' => $sale->price?->currencyCode,
            'starts_at' => $sale->startsAt->format('Y-m-d H:i:s.uP'),
            'ends_at' => $sale->endsAt?->format('Y-m-d H:i:s.uP'),
            'always_wins' => $sale->alwaysWins,
            'created_at' => CarbonImmutable::now(),
        ]], ['id'], ['percent_bp', 'amount_minor', 'currency_code', 'starts_at', 'ends_at', 'always_wins']);

        $this->db->table('pricing.sale_variants')->where('sale_id', $sale->id)->delete();
        $this->db->table('pricing.sale_variants')->insert(array_map(static fn (string $variantId): array => ['sale_id' => $sale->id, 'variant_id' => $variantId], $sale->variantIds));
    }

    public function remove(string $saleId): void
    {
        $this->db->table('pricing.sales')->where('id', $saleId)->delete();
    }

    public function notEndedOn(StoreId $store, array $variantIds, DateTimeImmutable $at): array
    {
        if ($variantIds === []) {
            return [];
        }

        return $this->sales($this->notEndedQuery($store, $at)
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('pricing.sale_variants as v')->whereColumn('v.sale_id', 's.id')->whereIn('v.variant_id', $variantIds))
            ->get()->values()->all());
    }

    public function notEnded(StoreId $store, DateTimeImmutable $at): array
    {
        return $this->sales($this->notEndedQuery($store, $at)->get()->values()->all());
    }

    private function notEndedQuery(StoreId $store, DateTimeImmutable $at): Builder
    {
        return $this->db->table('pricing.sales as s')
            ->where('s.store_id', $store->value)
            ->where(fn (Builder $query) => $query->whereNull('s.ends_at')->orWhere('s.ends_at', '>', $at->format('Y-m-d H:i:s.uP')))
            ->orderBy('s.starts_at')->orderBy('s.id')
            ->select('s.*');
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<Sale>
     */
    private function sales(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));

        if ($rows === []) {
            return [];
        }

        $sizes = [];

        foreach ($this->db->table('pricing.sale_variants')->whereIn('sale_id', array_map(static fn (stdClass $row): string => (string) $row->id, $rows))->orderBy('variant_id')->get() as $size) {
            $sizes[(string) $size->sale_id][] = (string) $size->variant_id;
        }

        return array_map(static fn (stdClass $row): Sale => new Sale(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->product_id,
            $row->percent_bp === null ? null : (int) $row->percent_bp,
            $row->amount_minor === null ? null : Money::of((int) $row->amount_minor, (string) $row->currency_code),
            CarbonImmutable::parse((string) $row->starts_at)->toDateTimeImmutable(),
            $row->ends_at === null ? null : CarbonImmutable::parse((string) $row->ends_at)->toDateTimeImmutable(),
            (bool) $row->always_wins,
            $sizes[(string) $row->id] ?? [],
        ), $rows);
    }
}
