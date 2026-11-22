<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SetRunPrices;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\ValueObject\PriceChoices;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Sets one run's prices (content.md §1.7 #4, #5, #14): the campaign's job and **Pricing's `pricing.price.edit` in that
 * store** (`CampaignPricesNotAllowed`, naming it). **A draft's choices stay in Content** - Pricing has no draft; its
 * candidates apply on their dates - and reach Pricing only at Schedule. A scheduled run's go to Pricing at once
 * (`CampaignPrices::save`, under its store's lock), refused once its start has come: **prices are fixed while a
 * campaign runs** (`CampaignNotChangeable` `prices_fixed`). Pricing's refusals (past the ceiling, a target not there)
 * reach the person in its own words. Refused over a newer version of the campaign.
 */
final readonly class SetRunPricesHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private CampaignRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(SetRunPrices $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->campaigns->findRun($command->runId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $this->rules->requirePriceRight($store);
        $choices = $command->prices === null ? null : PriceChoices::of($command->prices);
        $choices = $choices !== null && $choices->isEmpty() ? null : $choices;
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $found, $store, $choices, $staffId): void {
            $now = Moments::now();
            $campaign = $this->campaigns->lock((string) $found?->campaignId) ?? throw new ContentNotFound;
            $run = $this->campaigns->lockRun($command->runId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $campaign->version, $campaign->updatedBy, $campaign->updatedAt, $store);
            $this->rules->requirePricesOpen($run, $now);

            if ($run->state === RunState::Draft) {
                $changed = $run->with(choices: true, priceChoices: $choices);
            } else {
                $this->rules->savePrices($run, $choices, $store);
                $changed = $run;
            }

            $this->campaigns->updateRun($changed);
            $this->rules->keepTargets($run, $choices);
            $this->campaigns->update(new Campaign($campaign->id, $campaign->nameAr, $campaign->nameEn, $campaign->startsLocal, $campaign->endsLocal, $campaign->themeId, $campaign->version + 1, $staffId, $now));

            // The choices themselves can be long: recorded by their count and lines, as the run's short fields.
            $this->platform->recordAudit(ContentAudit::entry('content.campaign.prices_set', 'content.campaign_run', $run->id, $store->id, ContentAudit::diff(
                ['targets' => self::count($run->state === RunState::Draft ? $run->priceChoices : null), 'lines' => $run->priceChoices?->lines],
                ['targets' => self::count($choices), 'lines' => $choices?->lines],
            )));
        }, 3);
    }

    private static function count(?PriceChoices $choices): int
    {
        return $choices === null ? 0 : count($choices->targets);
    }
}
