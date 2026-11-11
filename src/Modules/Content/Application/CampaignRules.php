<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use DateTimeImmutable;
use Modules\Content\Domain\Exception\CampaignNotChangeable;
use Modules\Content\Domain\Exception\CampaignPricesNotAllowed;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\NoticeNotDraft;
use Modules\Content\Domain\Exception\NoticeTaken;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Domain\ValueObject\PriceChoices;
use Modules\Content\Domain\ValueObject\Slug;
use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Modules\Pricing\Public\Dto\CampaignPriceSetDto;
use Modules\Pricing\Public\Dto\CampaignTargetDto;
use Modules\Pricing\Public\Enums\CampaignLines;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a campaign's use cases share (content.md §1.7): the stores it may run in, its theme link, its notice, its
 * prices as Pricing takes them, and the run's life with Pricing.
 *
 * **Prices**: a draft's choices stay in Content (`price_choices`); only Schedule hands them to Pricing
 * (`CampaignPrices::save`, #14), after which Pricing is the record. Pricing judges "started" by the run's own dates,
 * so a run whose start has passed refuses price edits whatever Content's state says (PR 5's review), and Cancel and
 * End both call `endNow` (it removes a run not started and ends a started one).
 */
final readonly class CampaignRules
{
    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private Themes $themes,
        private CampaignNotices $notices,
        private CampaignPrices $prices,
    ) {}

    /**
     * The stores a change names, each authorized for the campaign's job, in store-id order (§1.7 #15, lesson 37).
     *
     * @param  list<string>  $storeIds
     * @return list<StoreDto>
     *
     * @throws ContentError
     */
    public function stores(array $storeIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $storeIds)));

        if ($ids === []) {
            throw new InvalidContentInput('stores', InvalidContentInput::TOO_FEW, values: ['min' => 1]);
        }

        sort($ids);

        return array_map(fn (string $id): StoreDto => $this->access->authorize(ContentPermissions::CAMPAIGN_MANAGE, $id), $ids);
    }

    /**
     * The runs a step names (§1.7 #12, #15): every run of the campaign, or one store's, in store-id order; none for an
     * unknown campaign or store. The caller authorizes each run's store (`item: true`), an empty list as one unknown.
     *
     * @return list<CampaignRun>
     */
    public function runsNamed(string $campaignId, ?string $storeId): array
    {
        $found = $this->campaigns->find($campaignId);

        return $found === null ? [] : array_values(array_filter($this->campaigns->runsOf($found->id), static fn (CampaignRun $run): bool => $storeId === null || $run->storeId === strtolower(trim($storeId))));
    }

    /**
     * A theme a campaign may link to (§1.7 #7): one that exists and has not ended. Linking changes nothing on it, so the
     * campaign's job is enough.
     *
     * @throws InvalidContentInput
     */
    public function theme(?string $themeId, DateTimeImmutable $now): ?string
    {
        $themeId = $themeId === null || trim($themeId) === '' ? null : strtolower(trim($themeId));

        if ($themeId === null) {
            return null;
        }

        $theme = $this->themes->find($themeId);

        if ($theme === null || $theme->hasEnded($now)) {
            throw new InvalidContentInput('theme', InvalidContentInput::THEME);
        }

        return $theme->id;
    }

    /**
     * A notice a run may carry (§1.7 #10): one of its store's **drafts** in Ops, read through the port, that no other
     * run holds while it may still send it.
     *
     * @throws NoticeNotDraft|NoticeTaken
     */
    public function notice(?string $noticeId, CampaignRun $run): ?string
    {
        $noticeId = $noticeId === null || trim($noticeId) === '' ? null : strtolower(trim($noticeId));

        if ($noticeId === null) {
            return null;
        }

        $this->requireDraftNotice($noticeId, $run->storeId);
        $holder = $this->campaigns->noticeHolder($noticeId);

        if ($holder !== null && $holder !== $run->id) {
            throw new NoticeTaken;
        }

        return $noticeId;
    }

    /**
     * The notice still a draft of its store (#10: one sent, cancelled or deleted in Ops meanwhile sends nothing).
     *
     * @throws NoticeNotDraft
     */
    public function requireDraftNotice(string $noticeId, string $storeId): void
    {
        foreach ($this->notices->named(StoreId::fromString($storeId), [$noticeId]) as $notice) {
            if (strtolower($notice->id) === strtolower($noticeId) && $notice->isDraft()) {
                return;
            }
        }

        throw new NoticeNotDraft;
    }

    /**
     * Prices in a store need Pricing's own permission there beside the campaign's (§1.7 #5, #16).
     *
     * @throws CampaignPricesNotAllowed
     */
    public function requirePriceRight(StoreDto $store): void
    {
        if (! $this->access->holdsIn(ContentPermissions::PRICING_PRICE_EDIT, $store->id)) {
            throw new CampaignPricesNotAllowed($store->name->in(app()->getLocale()));
        }
    }

    /**
     * A run's prices may still change: a draft, or a scheduled run whose start has not come (Pricing refuses one past
     * its start - so does this, first, in Content's words).
     *
     * @throws CampaignNotChangeable
     */
    public function requirePricesOpen(CampaignRun $run, DateTimeImmutable $now): void
    {
        if ($run->state === RunState::Draft) {
            return;
        }

        if ($run->state !== RunState::Scheduled || $run->startsAt <= $now) {
            throw new CampaignNotChangeable(CampaignNotChangeable::PRICES_FIXED);
        }
    }

    /** The choices as Pricing takes them, a fixed price in the store's currency. */
    public static function priceSet(PriceChoices $choices, StoreDto $store): CampaignPriceSetDto
    {
        return new CampaignPriceSetDto(
            CampaignLines::from($choices->lines),
            array_map(static fn (array $target): CampaignTargetDto => new CampaignTargetDto(
                CampaignTargetKind::from($target['kind']),
                $target['id'],
                $target['percentBp'],
                $target['amountMinor'] === null ? null : Money::of($target['amountMinor'], $store->currencyCode),
            ), $choices->targets),
            $choices->leftOut,
        );
    }

    /** What Pricing holds, as choices (for a run scheduled since: its categories and brands, and the form). */
    public static function choicesOf(CampaignPriceSetDto $set): PriceChoices
    {
        return PriceChoices::of([
            'lines' => $set->lines->value,
            'targets' => array_map(static fn (CampaignTargetDto $target): array => [
                'kind' => $target->kind->value,
                'id' => $target->id,
                'percentBp' => $target->percentBasisPoints,
                'amountMinor' => $target->amount?->minorUnits,
            ], $set->targets),
            'leftOut' => $set->leftOut,
        ]);
    }

    /**
     * The categories and brands a run names, kept with their keys while it may still price them (§1.1 rule 17).
     */
    public function keepTargets(CampaignRun $run, ?PriceChoices $choices): void
    {
        $this->campaigns->keepTargets($run->id, $choices?->idsOf('CATEGORY') ?? [], $choices?->idsOf('BRAND') ?? []);
    }

    /**
     * A scheduled run's prices handed to Pricing (only at Schedule, and when a scheduled run's prices or start change):
     * written or replaced; no target takes them away.
     */
    public function savePrices(CampaignRun $run, ?PriceChoices $choices, StoreDto $store): void
    {
        $id = StoreId::fromString($store->id);

        if ($choices === null || $choices->isEmpty()) {
            $this->prices->remove($id, $run->id);

            return;
        }

        $this->prices->save($id, $run->id, self::priceSet($choices, $store), $run->startsAt, $run->endsAt);
    }

    /** What Pricing holds for a run, as choices; null when it holds none. */
    public function heldPrices(CampaignRun $run): ?PriceChoices
    {
        $set = $this->prices->read(StoreId::fromString($run->storeId), $run->id);

        return $set === null ? null : self::choicesOf($set);
    }

    /** A scheduled or live run's prices end now, or go if they had not started (Cancel and End, PR 5's review). */
    public function endPrices(CampaignRun $run): void
    {
        $this->prices->endNow(StoreId::fromString($run->storeId), $run->id);
    }

    /** A scheduled or live run's prices end at its new end; a run Pricing holds nothing for changes nothing. */
    public function moveEnd(CampaignRun $run, DateTimeImmutable $end): void
    {
        $this->prices->changeEnd(StoreId::fromString($run->storeId), $run->id, $end);
    }

    /**
     * A run being scheduled gets its campaign page's address from the campaign's name, per language (H7; the build's
     * pick, content.md §9.3f F6), unless it has one; it is kept from then. A name that makes no address (only signs)
     * gives `campaign` and the next free number.
     */
    public function giveAddress(CampaignRun $run, Campaign $campaign): void
    {
        $slug = static function (string $locale, ?string $name): string {
            try {
                return Slug::fromTitle("slug_{$locale}", $locale, (string) $name)->value;
            } catch (InvalidContentInput) {
                return 'campaign';
            }
        };

        $this->campaigns->giveAddress($run->id, $run->storeId, $slug('ar', $campaign->nameAr), $slug('en', $campaign->nameEn ?? $campaign->nameAr));
    }
}
