<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\PreviewRunPrices;

use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\ValueObject\PriceChoices;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Modules\Pricing\Public\Dto\CampaignPreviewDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Preview before saving** (content.md §1.7 #6, #14; W4b's Prices tab): what these choices would do in the run's store
 * in its dates - each size's new price, the sizes skipped and why, every other offer on them then, the deepest
 * reduction - from Pricing's own preview, which writes nothing. The run is named, so it is not its own other offer
 * (PR 5's review). 20 products a page (`$after`).
 */
final readonly class PreviewRunPricesHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private CampaignPrices $prices,
    ) {}

    /**
     * @param  array<array-key, mixed>  $choices  as `PriceChoices` reads them
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(string $runId, array $choices, ?string $after = null): CampaignPreviewDto
    {
        $this->access->holds(self::PERMISSION);
        $run = $this->campaigns->findRun($runId);
        $store = $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);

        if ($run === null) {
            throw new ContentNotFound;
        }

        $set = CampaignRules::priceSet(PriceChoices::of($choices), $store);

        return $this->prices->preview(StoreId::fromString($store->id), $set, $run->startsAt, $run->endsAt, $after, $run->id);
    }
}
