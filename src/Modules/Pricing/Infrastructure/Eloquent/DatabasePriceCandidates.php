<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * `pricing.price_candidates` (pricing.md §1.6, §5): a size's rows in a store are deleted and written
 * again as one, inside the change's transaction, so no read sees half of them.
 */
final readonly class DatabasePriceCandidates implements PriceCandidates
{
    private const string TABLE = 'pricing.price_candidates';

    private const int ROWS_A_STATEMENT = 1000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function replace(StoreId $store, array $candidates): void
    {
        if ($candidates === []) {
            return;
        }

        $this->db->table(self::TABLE)->where('store_id', $store->value)->whereIn('variant_id', array_map('strval', array_keys($candidates)))->delete();
        $rows = [];

        foreach ($candidates as $variantId => $ofSize) {
            foreach ($ofSize as $candidate) {
                $rows[] = [
                    'store_id' => $store->value,
                    'variant_id' => (string) $variantId,
                    'mode' => $candidate->mode->value,
                    'from_quantity' => $candidate->fromQuantity,
                    'amount_minor' => $candidate->amount->minorUnits,
                    'currency_code' => $candidate->amount->currencyCode,
                    'kind' => $candidate->kind->value,
                    'always_wins' => $candidate->alwaysWins,
                    'source_id' => $candidate->sourceId,
                    'starts_at' => $candidate->startsAt?->format('Y-m-d H:i:s.uP'),
                    'ends_at' => $candidate->endsAt?->format('Y-m-d H:i:s.uP'),
                ];
            }
        }

        // Eleven columns a row: a thousand rows a statement stays far under 65,535 parameters.
        foreach (array_chunk($rows, self::ROWS_A_STATEMENT) as $chunk) {
            $this->db->table(self::TABLE)->insert($chunk);
        }
    }

    public function forVariants(StoreId $store, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->whereIn('variant_id', $variantIds)
            ->orderBy('id')
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[(string) $row->variant_id][] = new Candidate(
                SaleMode::from((string) $row->mode),
                (int) $row->from_quantity,
                Money::of((int) $row->amount_minor, (string) $row->currency_code),
                PriceKind::from((string) $row->kind),
                (bool) $row->always_wins,
                self::moment($row->starts_at),
                self::moment($row->ends_at),
                $row->source_id === null ? null : (string) $row->source_id,
            );
        }

        return $candidates;
    }

    public function variantsWithCandidates(StoreId $store): array
    {
        return array_values(array_map('strval', $this->db->table(self::TABLE)->where('store_id', $store->value)->distinct()->orderBy('variant_id')->pluck('variant_id')->all()));
    }

    private static function moment(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toDateTimeImmutable();
    }
}
