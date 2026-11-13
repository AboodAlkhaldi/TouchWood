<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Pricing\Domain\Repository\Campaigns;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Domain\ValueObject\CampaignTarget;
use Modules\Pricing\Domain\ValueObject\DiscountLines;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `pricing.campaign_sets`, `campaign_targets`, `campaign_left_out` and `campaign_skips` (pricing.md §9.6).
 * A run's rows are read in three queries however many runs: the sets, then their targets and left-out
 * products together by run.
 */
final readonly class DatabaseCampaigns implements Campaigns
{
    private const string SETS = 'pricing.campaign_sets';

    private const string TARGETS = 'pricing.campaign_targets';

    private const string LEFT_OUT = 'pricing.campaign_left_out';

    private const string SKIPS = 'pricing.campaign_skips';

    /** Rows a statement, far under PostgreSQL's 65,535 parameters. */
    private const int ROWS_A_STATEMENT = 1000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $runId): ?Campaign
    {
        $row = $this->db->table(self::SETS)->where('run_id', strtolower(trim($runId)))->first();

        return $row instanceof stdClass ? $this->campaigns([$row])[0] : null;
    }

    public function save(Campaign $campaign): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::SETS)->upsert([[
            'run_id' => $campaign->runId,
            'store_id' => $campaign->storeId,
            'lines' => $campaign->lines->value,
            'starts_at' => $campaign->startsAt->format('Y-m-d H:i:s.uP'),
            'ends_at' => $campaign->endsAt?->format('Y-m-d H:i:s.uP'),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['run_id'], ['lines', 'starts_at', 'ends_at', 'updated_at']);

        $this->db->table(self::TARGETS)->where('run_id', $campaign->runId)->delete();

        foreach (array_chunk(array_map(static fn (CampaignTarget $target): array => [
            'run_id' => $campaign->runId,
            'kind' => $target->kind->value,
            'target_id' => $target->id,
            'percent_bp' => $target->percentBasisPoints,
            'amount_minor' => $target->amount?->minorUnits,
            'currency_code' => $target->amount?->currencyCode,
        ], $campaign->targets), self::ROWS_A_STATEMENT) as $rows) {
            $this->db->table(self::TARGETS)->insert($rows);
        }

        $this->db->table(self::LEFT_OUT)->where('run_id', $campaign->runId)->delete();

        foreach (array_chunk(array_map(static fn (string $productId): array => ['run_id' => $campaign->runId, 'product_id' => $productId], $campaign->leftOut), self::ROWS_A_STATEMENT) as $rows) {
            $this->db->table(self::LEFT_OUT)->insert($rows);
        }
    }

    public function remove(string $runId): void
    {
        $this->db->table(self::SETS)->where('run_id', $runId)->delete();
    }

    public function notEnded(StoreId $store, DateTimeImmutable $at): array
    {
        return $this->campaigns($this->db->table(self::SETS)
            ->where('store_id', $store->value)
            ->where('ends_at', '>', $at->format('Y-m-d H:i:s.uP'))
            ->orderBy('starts_at')->orderBy('run_id')
            ->get()->values()->all());
    }

    public function storesWithOffers(DateTimeImmutable $at): array
    {
        $moment = $at->format('Y-m-d H:i:s.uP');
        $found = ['discounts' => [], 'campaigns' => []];

        foreach ($this->db->select(
            "SELECT DISTINCT 'discounts' AS kind, store_id FROM pricing.category_discounts WHERE ends_at IS NULL OR ends_at > ?"
            ." UNION SELECT DISTINCT 'campaigns' AS kind, store_id FROM pricing.campaign_sets WHERE ends_at > ?"
            .' ORDER BY kind, store_id',
            [$moment, $moment],
        ) as $row) {
            $found[$row->kind === 'discounts' ? 'discounts' : 'campaigns'][] = (string) $row->store_id;
        }

        return $found;
    }

    public function windowsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$after, $by] = [$from->format('Y-m-d H:i:s.uP'), $to->format('Y-m-d H:i:s.uP')];

        return $this->campaigns($this->db->table(self::SETS)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $start) => $start->where('starts_at', '>', $after)->where('starts_at', '<=', $by))
                ->orWhere(fn (Builder $end) => $end->where('ends_at', '>', $after)->where('ends_at', '<=', $by)))
            ->orderBy('store_id')->orderBy('run_id')
            ->get()->values()->all());
    }

    public function replaceSkips(StoreId $store, array $variantIds, array $skipped): void
    {
        foreach (array_chunk($variantIds, self::ROWS_A_STATEMENT) as $chunk) {
            $this->db->table(self::SKIPS)->where('store_id', $store->value)->whereIn('variant_id', $chunk)->delete();
        }

        $rows = [];

        foreach ($skipped as $skip) {
            $rows[$skip['runId'].'|'.$skip['variantId']] = ['run_id' => $skip['runId'], 'variant_id' => $skip['variantId'], 'store_id' => $store->value];
        }

        foreach (array_chunk(array_values($rows), self::ROWS_A_STATEMENT) as $chunk) {
            $this->db->table(self::SKIPS)->insert($chunk);
        }
    }

    public function skippedSizes(StoreId $store, string $runId): array
    {
        return array_values(array_map('strval', $this->db->table(self::SKIPS)
            ->where('store_id', $store->value)
            ->where('run_id', strtolower(trim($runId)))
            ->orderBy('variant_id')
            ->pluck('variant_id')->all()));
    }

    public function reachedSizes(array $runIds): array
    {
        $ids = array_values(array_filter(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $runIds)), static fn (string $id): bool => preg_match('/\A[0-9a-z]{26}\z/', $id) === 1));

        if ($ids === []) {
            return [];
        }

        $reached = [];

        // A size a run prices on both lines has a candidate on each: counted once.
        foreach ($this->db->table('pricing.price_candidates')->where('kind', PriceKind::Campaign->value)->whereIn('source_id', $ids)->groupBy('source_id')->get(['source_id', $this->db->raw('count(DISTINCT variant_id) AS n')]) as $row) {
            /** @var stdClass $row */
            $reached[(string) $row->source_id] = (int) $row->n;
        }

        return $reached;
    }

    public function skipsOf(StoreId $store, array $variantIds): array
    {
        $ids = array_values(array_filter(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $variantIds)), static fn (string $id): bool => preg_match('/\A[0-9a-z]{1,64}\z/', $id) === 1));

        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach ($this->db->table(self::SKIPS)->where('store_id', $store->value)->whereRaw('variant_id = ANY(?::text[])', ['{'.implode(',', $ids).'}'])
            ->orderBy('variant_id')->orderBy('run_id')->get(['variant_id', 'run_id']) as $row) {
            $found[(string) $row->variant_id][] = (string) $row->run_id;
        }

        return $found;
    }

    public function skipsInStore(StoreId $store): array
    {
        $found = [];

        foreach ($this->db->table(self::SKIPS)->where('store_id', $store->value)->orderBy('variant_id')->orderBy('run_id')->get(['variant_id', 'run_id']) as $row) {
            $found[(string) $row->variant_id][] = (string) $row->run_id;
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<Campaign>
     */
    private function campaigns(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));

        if ($rows === []) {
            return [];
        }

        $runIds = array_map(static fn (stdClass $row): string => (string) $row->run_id, $rows);
        $targets = $leftOut = [];

        foreach ($this->db->table(self::TARGETS)->whereIn('run_id', $runIds)->orderBy('kind')->orderBy('target_id')->get() as $target) {
            $targets[(string) $target->run_id][] = new CampaignTarget(
                CampaignTargetKind::from((string) $target->kind),
                (string) $target->target_id,
                $target->percent_bp === null ? null : (int) $target->percent_bp,
                $target->amount_minor === null ? null : Money::of((int) $target->amount_minor, (string) $target->currency_code),
            );
        }

        foreach ($this->db->table(self::LEFT_OUT)->whereIn('run_id', $runIds)->orderBy('product_id')->get() as $row) {
            $leftOut[(string) $row->run_id][] = (string) $row->product_id;
        }

        return array_map(static fn (stdClass $row): Campaign => new Campaign(
            (string) $row->run_id,
            (string) $row->store_id,
            DiscountLines::from((string) $row->lines),
            CarbonImmutable::parse((string) $row->starts_at)->toDateTimeImmutable(),
            CarbonImmutable::parse((string) $row->ends_at)->toDateTimeImmutable(),
            $targets[(string) $row->run_id] ?? [],
            $leftOut[(string) $row->run_id] ?? [],
        ), $rows);
    }
}
