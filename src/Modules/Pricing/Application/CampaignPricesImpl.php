<?php

declare(strict_types=1);

namespace Modules\Pricing\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Prices\CampaignCheck;
use Modules\Pricing\Application\Prices\CampaignCoverage;
use Modules\Pricing\Application\Prices\CampaignInput;
use Modules\Pricing\Application\Prices\CampaignPreviewer;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PriceWindowTasks;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Domain\Repository\Campaigns;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Domain\ValueObject\CampaignTarget;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Modules\Pricing\Public\Dto\CampaignPreviewDto;
use Modules\Pricing\Public\Dto\CampaignPriceSetDto;
use Modules\Pricing\Public\Dto\CampaignTargetDto;
use Modules\Pricing\Public\Enums\CampaignLines;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * `CampaignPrices` (pricing.md §9.6 #8). Every change runs as every price change does (`PricingChange::run`):
 * the store's pricing lock first, inside the caller's transaction when there is one - Content's Schedule,
 * one store at a time in store-id order (content.md §1.7 #15) - the sizes the run reached before and those
 * it reaches now written again and Catalog told, the timed tasks queued after the commit. No permission
 * here and no audit entry: Content checks both permissions and audits the campaign (content.md §1.1 #13).
 *
 * The build's picks, stated in the PR: a run's prices are fixed once it runs - saving them again is refused
 * as a running sale's are (Content's form is read-only then); `endNow` removes a run not started yet;
 * `remove` refuses a run that has started; a run Pricing does not hold is nothing to end or remove (a
 * campaign may carry no prices, §1.7 #16).
 */
final readonly class CampaignPricesImpl implements CampaignPrices
{
    /** Products a page of `productIds`, at most. */
    private const int MOST = 100;

    public function __construct(
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private PricingChange $change,
        private Campaigns $campaigns,
        private CampaignCoverage $coverage,
        private CampaignCheck $check,
        private CampaignPreviewer $previewer,
        private CandidateWriter $writer,
        private PriceWindowTasks $tasks,
    ) {}

    public function preview(StoreId $store, CampaignPriceSetDto $prices, DateTimeImmutable $start, DateTimeImmutable $end, ?string $after = null, ?string $runId = null): CampaignPreviewDto
    {
        $found = $this->store($store);
        $itself = $runId === null ? null : CampaignInput::runId($runId);
        // Judged as it would be saved, under a name of its own when it has none yet.
        $campaign = CampaignInput::campaign($found, $itself ?? str_repeat('0', 26), $prices, $start, $end);

        return $this->previewer->preview($found, $campaign, $after === null ? null : strtolower(trim($after)), $itself);
    }

    public function save(StoreId $store, string $runId, CampaignPriceSetDto $prices, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $found = $this->store($store);
        $campaign = CampaignInput::campaign($found, $runId, $prices, $start, $end);

        $this->change->run($store, function () use ($store, $campaign): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();

            if ($campaign->ended($now)) {
                throw new WindowInvalid;
            }

            $before = $this->held($store, $campaign->runId);

            // Prices are fixed while a run runs, and an ended one is kept as it was (content.md §1.7 #8).
            if ($before !== null && ! $before->scheduled($now)) {
                throw new NotChangeable;
            }

            $this->check->check($store, $campaign);
            $sizes = $this->coverage->pricedSizes($store, $before === null ? [$campaign] : [$before, $campaign]);
            $this->campaigns->save($campaign);
            $this->writer->refresh($store, $sizes, campaignsChanged: true);
            $this->tasks->queue($store, $campaign->runId, $campaign->momentsAfter($now));

            return [null, []];
        });
    }

    public function changeEnd(StoreId $store, string $runId, DateTimeImmutable $end): void
    {
        $this->store($store);
        $runId = CampaignInput::runId($runId);

        $this->change->run($store, function () use ($store, $runId, $end): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $before = $this->held($store, $runId) ?? throw new InvalidPricingInput('campaign');

            if ($before->ended($now)) {
                throw new NotChangeable;
            }

            if ($end <= $before->startsAt || $end <= $now) {
                throw new WindowInvalid;
            }

            $after = self::endingAt($before, $end);
            $this->campaigns->save($after);
            $this->writer->refresh($store, $this->coverage->pricedSizes($store, [$after]), campaignsChanged: true);
            $this->tasks->queue($store, $after->runId, $after->momentsAfter($now));

            return [null, []];
        });
    }

    public function endNow(StoreId $store, string $runId): void
    {
        $this->store($store);
        $runId = CampaignInput::runId($runId);

        $this->change->run($store, function () use ($store, $runId): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $before = $this->held($store, $runId);

            if ($before === null || $before->ended($now)) {
                return [null, []];
            }

            $sizes = $this->coverage->pricedSizes($store, [$before]);

            if ($before->scheduled($now)) {
                $this->campaigns->remove($before->runId);
            } else {
                $this->campaigns->save(self::endingAt($before, $now));
            }

            $this->writer->refresh($store, $sizes, campaignsChanged: true);

            return [null, []];
        });
    }

    public function remove(StoreId $store, string $runId): void
    {
        $this->store($store);
        $runId = CampaignInput::runId($runId);

        $this->change->run($store, function () use ($store, $runId): array {
            $before = $this->held($store, $runId);

            if ($before === null) {
                return [null, []];
            }

            // Started: ended now instead, never removed (§4.1, as a running sale).
            if (! $before->scheduled(CarbonImmutable::now()->toDateTimeImmutable())) {
                throw new NotChangeable;
            }

            $sizes = $this->coverage->pricedSizes($store, [$before]);
            $this->campaigns->remove($before->runId);
            $this->writer->refresh($store, $sizes, campaignsChanged: true);

            return [null, []];
        });
    }

    public function read(StoreId $store, string $runId): ?CampaignPriceSetDto
    {
        $campaign = $this->here($store, $runId);

        return $campaign === null ? null : new CampaignPriceSetDto(
            CampaignLines::from($campaign->lines->value),
            array_map(static fn (CampaignTarget $target): CampaignTargetDto => new CampaignTargetDto($target->kind, $target->id, $target->percentBasisPoints, $target->amount), $campaign->targets),
            $campaign->leftOut,
        );
    }

    public function productIds(StoreId $store, string $runId, ?string $after, int $limit): array
    {
        $campaign = $this->here($store, $runId);

        if ($campaign === null) {
            return [];
        }

        $products = $this->coverage->products([$campaign])[$campaign->runId] ?? [];
        $sizes = $campaign->targetIds(CampaignTargetKind::Size);

        foreach ($sizes === [] ? [] : $this->catalog->variants($sizes) as $variant) {
            $products[$variant->productId] = true;
        }

        $ids = array_map('strval', array_keys($products));
        sort($ids, SORT_STRING);
        $from = $after === null ? '' : strtolower(trim($after));

        return array_slice(array_values(array_filter($ids, static fn (string $id): bool => strcmp($id, $from) > 0)), 0, max(1, min($limit, self::MOST)));
    }

    public function skippedSizes(StoreId $store, string $runId): array
    {
        return $this->campaigns->skippedSizes($store, strtolower(trim($runId)));
    }

    /** The run in this store, for a read: one of another store, or no id at all, is none here. */
    private function here(StoreId $store, string $runId): ?Campaign
    {
        $campaign = $this->campaigns->find($runId);

        return $campaign !== null && $campaign->storeId === $store->value ? $campaign : null;
    }

    /**
     * @throws InvalidPricingInput
     */
    private function store(StoreId $store): StoreDto
    {
        return $this->platform->store($store) ?? throw new InvalidPricingInput('store');
    }

    /**
     * The run as Pricing holds it in this store; one of another store is refused, never read.
     *
     * @throws InvalidPricingInput
     */
    private function held(StoreId $store, string $runId): ?Campaign
    {
        $campaign = $this->campaigns->find($runId);

        if ($campaign !== null && $campaign->storeId !== $store->value) {
            throw new InvalidPricingInput('campaign');
        }

        return $campaign;
    }

    private static function endingAt(Campaign $campaign, DateTimeImmutable $end): Campaign
    {
        return new Campaign($campaign->runId, $campaign->storeId, $campaign->lines, $campaign->startsAt, $end, $campaign->targets, $campaign->leftOut);
    }
}
