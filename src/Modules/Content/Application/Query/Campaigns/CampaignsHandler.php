<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Campaigns;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundProductDto;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Content\Domain\ValueObject\PriceChoices;
use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Content\Public\Dto\NoticeChoiceDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What W4 and W4b show (content.md §4.4), under `content.campaign.manage`:
 *
 * - **the list** in a store: its campaigns, newest first, 50 at a time, by the store's run's state (a tab), with each
 *   tab's count;
 * - **a campaign's page**: its shared fields, and **each run** as its tab shows it - its prices as the form edits them (a
 *   draft's from Content, a scheduled or later one's from Pricing, which holds them from Schedule on, §1.7 #14),
 *   **whether they may still change** (a draft, or a scheduled run whose start has not come: Pricing judges "started"
 *   by the run's own dates, whatever Content's state says), whether the reader holds Pricing's own right there (§1.7
 *   #5), the store's discount ceiling, the sizes the run skips now (§1.7 #17, from Pricing), its notice and the store's
 *   draft notices (§1.7 #10, from Ops through the port), and the names of everything its choices name - an archived
 *   product or size marked so, never hidden (§1.1 rule 17); and **the linked theme's card**: its name, its parts with a
 *   filled mark, and store by store whether its dates match the run's (Q6 (a)).
 * - **the targets** a price may name, searched in the run's store: products and sizes the store sells (ready, switched
 *   on), categories and brands switched on.
 *
 * Nothing here is Pricing's preview: the page asks for it as the prices are typed (`PreviewRunPricesHandler`), so a
 * page of several stores never waits for each store's preview before it draws.
 */
final readonly class CampaignsHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    /** How many of a store's draft notices the Notice card offers (newest first). */
    public const int NOTICES = 50;

    /** How many targets one search answers. */
    public const int TARGETS = 20;

    private const string CEILING = 'pricing.ceiling.max_discount_percent';

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private Themes $themes,
        private ScreenReads $reads,
        private CampaignPrices $prices,
        private CampaignNotices $notices,
        private UnreadTranslations $marks,
        private PlatformApi $platform,
        private CatalogApi $catalog,
    ) {}

    /**
     * @return array{store: StoreDto, rows: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws ContentError|Unauthorized
     */
    public function list(?string $storeId, ?string $tab, ?string $after = null): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);
        // Only the campaigns the reader may open: every one of whose stores they hold the job in (§1.7 #5).
        $read = $this->reads->campaigns($store->id, $tab, $after, $this->access->storesHeld(self::PERMISSION));
        // How many sizes each run prices, for every run listed in one read of Pricing's (W4's Offers).
        $runIds = array_merge([], ...array_map(static fn (array $row): array => array_map(static fn (array $run): string => (string) $run['runId'], (array) $row['runs']), $read['rows']));
        $offers = $runIds === [] ? [] : $this->prices->reachedSizes($runIds);
        $read['rows'] = array_map(static fn (array $row): array => [...$row, 'runs' => array_map(static fn (array $run): array => [...$run, 'offers' => $offers[(string) $run['runId']] ?? null], (array) $row['runs'])], $read['rows']);
        $notices = array_values(array_unique(array_filter(array_map(static fn (array $row): ?string => is_string($row['noticeId'] ?? null) ? $row['noticeId'] : null, $read['rows']))));
        $named = [];

        foreach ($notices === [] ? [] : $this->notices->named(StoreId::fromString($store->id), $notices) as $notice) {
            $named[strtolower($notice->id)] = $notice;
        }

        $rows = array_map(static function (array $row) use ($named): array {
            $notice = is_string($row['noticeId'] ?? null) ? ($named[strtolower($row['noticeId'])] ?? null) : null;

            return [...$row, 'notice' => $notice === null ? null : ['titleAr' => $notice->titleAr, 'titleEn' => $notice->titleEn, 'status' => $notice->status]];
        }, $read['rows']);

        return ['store' => $store, 'rows' => $rows, 'counts' => $read['counts']];
    }

    /**
     * @return array{campaign: Campaign, runs: list<array<string, mixed>>, theme: array<string, mixed>|null, themes: list<array{id: string, nameAr: string, nameEn: string|null}>, unread: bool}
     *
     * @throws ContentError|Unauthorized
     */
    public function page(string $campaignId): array
    {
        $this->access->holds(self::PERMISSION);
        $campaign = $this->campaigns->find($campaignId);
        $runs = $campaign === null ? [] : $this->campaigns->runsOf($campaign->id);
        $stores = [];

        // Every store it runs in, before anything is read of it (§1.7 #5): one the reader holds nothing in is not found.
        foreach ($runs === [] ? [null] : $runs as $run) {
            $store = $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);
            $stores[$store->id] = $store;
        }

        if ($campaign === null) {
            throw new ContentNotFound;
        }

        $now = Moments::now();
        $choices = [];
        $skipped = [];

        foreach ($runs as $run) {
            $store = $stores[$run->storeId];
            $choices[$run->id] = $run->state === RunState::Draft ? $run->priceChoices : $this->held($run, $store);
            // Only a run Pricing holds can skip a size (§1.7 #17); a draft holds nothing there yet.
            $skipped[$run->id] = $run->state === RunState::Scheduled || $run->state === RunState::Live ? $this->prices->skippedSizes(StoreId::fromString($store->id), $run->id) : [];
        }

        $names = $this->names(array_values(array_filter($choices)), array_merge([], ...array_values($skipped)));

        return [
            'campaign' => $campaign,
            'runs' => array_map(fn (CampaignRun $run): array => $this->run($run, $stores[$run->storeId], $choices[$run->id], $skipped[$run->id], $names, $now), $runs),
            'theme' => $campaign->themeId === null ? null : $this->themeCard($campaign->themeId, $runs, $now),
            'themes' => $this->themeChoices($campaign->themeId, $now),
            'unread' => $this->marks->has(UnreadTranslations::CAMPAIGN, $campaign->id),
        ];
    }

    /**
     * The store a refusal's sizes are of, for the page to mark them in that store's tab alone: a run's store; or, for a
     * Schedule refused before any store was scheduled, its first draft run's in store-id order (one store named, that
     * one). Asked only once a refusal has named sizes, which only Pricing does, past every permission check.
     */
    public function refusedStore(?string $runId, ?string $campaignId = null, ?string $storeId = null): ?string
    {
        if ($runId !== null) {
            return $this->campaigns->findRun($runId)?->storeId;
        }

        $campaign = $campaignId === null ? null : $this->campaigns->find($campaignId);

        foreach ($campaign === null ? [] : $this->campaigns->runsOf($campaign->id) as $run) {
            if ($run->state === RunState::Draft && ($storeId === null || $run->storeId === strtolower($storeId))) {
                return $run->storeId;
            }
        }

        return null;
    }

    /**
     * The themes a new campaign may link to.
     *
     * @return list<array{id: string, nameAr: string, nameEn: string|null}>
     *
     * @throws Unauthorized
     */
    public function themesOnOffer(): array
    {
        $this->access->holds(self::PERMISSION);

        return $this->reads->themeChoices(Moments::now());
    }

    /**
     * What a price may name in the run's store, found by a name or a code: products and sizes the store sells, and
     * categories and brands switched on, 20 a page after the cursor.
     *
     * @return array{targets: list<array{id: string, nameAr: string, nameEn: string|null, detail: string|null}>, next: string|null}
     *
     * @throws ContentError|Unauthorized
     */
    public function targets(string $runId, string $kind, ?string $text, ?string $after): array
    {
        $this->access->holds(self::PERMISSION);
        $run = $this->campaigns->findRun($runId);
        $store = $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);
        $id = StoreId::fromString($store->id);

        return match ($kind) {
            'PRODUCT' => (function () use ($id, $text, $after): array {
                $found = $this->catalog->searchProducts($id, $text, true, null, $after, self::TARGETS);

                return ['targets' => array_map(static fn (FoundProductDto $product): array => ['id' => strtolower($product->productId), 'nameAr' => $product->nameAr, 'nameEn' => $product->nameEn, 'detail' => implode(' · ', $product->codes)], $found->rows), 'next' => $found->next];
            })(),
            'SIZE' => (function () use ($id, $text, $after): array {
                // The sizes switched on in the store, none archived.
                $found = $this->catalog->searchVariants($id, $text, after: $after, limit: self::TARGETS, notArchived: true, withTotal: false);

                return ['targets' => array_map(static fn (FoundVariantDto $size): array => ['id' => strtolower($size->variantId), 'nameAr' => $size->productNameAr, 'nameEn' => $size->productNameEn, 'detail' => $size->code], $found->rows), 'next' => $found->next];
            })(),
            'CATEGORY' => self::listPage(array_map(static fn ($category): array => [
                'id' => strtolower($category->id),
                'nameAr' => implode(' › ', array_map(static fn ($name): string => $name->ar, $category->path)),
                'nameEn' => implode(' › ', array_map(static fn ($name): string => $name->en, $category->path)),
                'detail' => null,
            ], $this->catalog->activeCategories()), $text, $after),
            'BRAND' => self::listPage(array_map(static fn ($brand): array => ['id' => strtolower($brand->id), 'nameAr' => $brand->nameAr, 'nameEn' => $brand->nameEn, 'detail' => null], $this->catalog->activeBrands()), $text, $after),
            default => throw new InvalidContentInput('kind', InvalidContentInput::CHOICE),
        };
    }

    /**
     * Categories or brands switched on - Catalog reads them whole - as the other targets come: found by a name in either
     * language, 20 a page after the cursor (the position of the page's last, as text).
     *
     * @param  list<array{id: string, nameAr: string, nameEn: string|null, detail: string|null}>  $all
     * @return array{targets: list<array{id: string, nameAr: string, nameEn: string|null, detail: string|null}>, next: string|null}
     */
    private static function listPage(array $all, ?string $text, ?string $after): array
    {
        $typed = mb_strtolower(trim((string) $text));
        $found = array_values(array_filter($all, static fn (array $each): bool => $typed === '' || str_contains(mb_strtolower($each['nameAr'].' '.($each['nameEn'] ?? '')), $typed)));
        $from = $after !== null && preg_match('/\A\d{1,6}\z/', $after) === 1 ? (int) $after : 0;
        $page = array_slice($found, $from, self::TARGETS);

        return ['targets' => $page, 'next' => $from + self::TARGETS < count($found) ? (string) ($from + self::TARGETS) : null];
    }

    /**
     * A scheduled or later run's prices, as Pricing holds them; null when it holds none.
     */
    private function held(CampaignRun $run, StoreDto $store): ?PriceChoices
    {
        $set = $this->prices->read(StoreId::fromString($store->id), $run->id);

        return $set === null ? null : CampaignRules::choicesOf($set);
    }

    /**
     * Everything the runs' choices and skipped sizes name, by kind and id: one read a kind for every store at once.
     *
     * @param  list<PriceChoices>  $choices
     * @param  list<string>  $skipped
     * @return array<string, array<string, array{nameAr: string, nameEn: string|null, detail: string|null, archived: bool}>>
     */
    private function names(array $choices, array $skipped): array
    {
        $ids = ['PRODUCT' => [], 'SIZE' => $skipped, 'CATEGORY' => [], 'BRAND' => []];

        foreach ($choices as $set) {
            foreach ($set->targets as $target) {
                $ids[$target['kind']][] = $target['id'];
            }

            array_push($ids['PRODUCT'], ...$set->leftOut);
        }

        $ids = array_map(static fn (array $list): array => array_values(array_unique(array_map(strtolower(...), $list))), $ids);
        $sizes = $ids['SIZE'] === [] ? [] : $this->catalog->variants($ids['SIZE']);
        $productIds = array_values(array_unique([...$ids['PRODUCT'], ...array_map(static fn ($size): string => strtolower($size->productId), array_values($sizes))]));
        $products = $productIds === [] ? [] : $this->catalog->products($productIds);
        $names = ['PRODUCT' => [], 'SIZE' => [], 'CATEGORY' => [], 'BRAND' => []];

        foreach ($products as $id => $product) {
            $names['PRODUCT'][strtolower($id)] = ['nameAr' => $product->nameAr, 'nameEn' => $product->nameEn, 'detail' => null, 'archived' => $product->stage === ProductStage::Archived];
        }

        foreach ($sizes as $id => $size) {
            $product = $names['PRODUCT'][strtolower($size->productId)] ?? null;
            $names['SIZE'][strtolower($id)] = ['nameAr' => $product['nameAr'] ?? $size->code, 'nameEn' => $product['nameEn'] ?? null, 'detail' => $size->code, 'archived' => $size->isArchived || ($product['archived'] ?? false)];
        }

        foreach ($ids['CATEGORY'] === [] ? [] : $this->catalog->categoriesWithPaths($ids['CATEGORY']) as $id => $category) {
            $names['CATEGORY'][strtolower($id)] = [
                'nameAr' => implode(' › ', array_map(static fn ($name): string => $name->ar, $category->path)),
                'nameEn' => implode(' › ', array_map(static fn ($name): string => $name->en, $category->path)),
                'detail' => null,
                'archived' => ! $category->isActive,
            ];
        }

        foreach ($ids['BRAND'] === [] ? [] : $this->catalog->brands($ids['BRAND']) as $id => $brand) {
            $names['BRAND'][strtolower($id)] = ['nameAr' => $brand->nameAr, 'nameEn' => $brand->nameEn, 'detail' => null, 'archived' => ! $brand->isActive];
        }

        return $names;
    }

    /**
     * One run as its tab shows it.
     *
     * @param  list<string>  $skipped
     * @param  array<string, array<string, array{nameAr: string, nameEn: string|null, detail: string|null, archived: bool}>>  $names
     * @return array<string, mixed>
     */
    private function run(CampaignRun $run, StoreDto $store, ?PriceChoices $choices, array $skipped, array $names, \DateTimeImmutable $now): array
    {
        $named = static fn (string $kind, string $id): array => $names[$kind][strtolower($id)] ?? ['nameAr' => $id, 'nameEn' => null, 'detail' => null, 'archived' => true];
        $notice = null;

        if ($run->noticeId !== null) {
            $found = array_values(array_filter($this->notices->named(StoreId::fromString($store->id), [$run->noticeId]), static fn (NoticeChoiceDto $each): bool => strtolower($each->id) === strtolower((string) $run->noticeId)));
            // One deleted in Ops reads as gone (§1.7 #10).
            $notice = $found === [] ? ['id' => $run->noticeId, 'titleAr' => null, 'titleEn' => null, 'status' => 'GONE'] : ['id' => strtolower($found[0]->id), 'titleAr' => $found[0]->titleAr, 'titleEn' => $found[0]->titleEn, 'status' => $found[0]->status];
        }

        $mayNotice = $run->state === RunState::Draft || $run->state === RunState::Scheduled;
        $ceiling = $this->platform->setting(self::CEILING, StoreId::fromString($store->id))->value;

        return [
            'id' => $run->id,
            'store' => $store,
            'state' => $run->state->value,
            'wentLive' => $run->wentLiveAt !== null,
            'startsAt' => $run->startsAt->format(DATE_ATOM),
            'endsAt' => $run->endsAt->format(DATE_ATOM),
            // Pricing judges "started" by the run's own dates (PR 5's review): past its start, a scheduled run's
            // prices are fixed whatever Content's state says.
            'pricesOpen' => $run->state === RunState::Draft || ($run->state === RunState::Scheduled && $run->startsAt > $now),
            'mayPrice' => $this->access->holdsIn(ContentPermissions::PRICING_PRICE_EDIT, $store->id),
            'mayNotices' => $this->access->holdsIn(ContentPermissions::OPS_NOTICE_MANAGE, $store->id),
            'ceilingPercent' => is_int($ceiling) ? $ceiling : (is_numeric($ceiling) ? (int) $ceiling : null),
            'prices' => $choices === null ? null : [
                'lines' => $choices->lines,
                'targets' => array_map(static fn (array $target): array => [...$target, ...$named($target['kind'], $target['id'])], $choices->targets),
                'leftOut' => array_map(static fn (string $id): array => ['id' => $id, ...$named('PRODUCT', $id)], $choices->leftOut),
            ],
            'skipped' => array_map(static fn (string $id): array => ['id' => strtolower($id), ...$named('SIZE', $id)], $skipped),
            'notice' => $notice,
            // Its store's drafts, offered while the run may still take one (§1.7 #10): after go-live it was sent.
            'drafts' => $mayNotice ? array_map(static fn (NoticeChoiceDto $each): array => ['id' => strtolower($each->id), 'titleAr' => $each->titleAr, 'titleEn' => $each->titleEn], $this->notices->drafts(StoreId::fromString($store->id), null, self::NOTICES)) : [],
        ];
    }

    /**
     * The linked theme as the campaign's page shows it (§1.7 #7): its name, its parts in §1.8's order each with a filled
     * mark, and, store by store, whether the theme's moments there are the run's.
     *
     * @param  list<CampaignRun>  $runs
     * @return array<string, mixed>|null
     */
    private function themeCard(string $themeId, array $runs, \DateTimeImmutable $now): ?array
    {
        $theme = $this->themes->find($themeId);

        if (! $theme instanceof Theme) {
            return null;
        }

        $filled = array_map(static fn ($part): string => $part->part->value, $this->themes->partsOf($theme->id));

        return [
            'id' => $theme->id,
            'nameAr' => $theme->nameAr,
            'nameEn' => $theme->nameEn,
            'startsLocal' => $theme->startsLocal,
            'endsLocal' => $theme->endsLocal,
            'state' => $theme->hasEnded($now) ? 'ENDED' : ($theme->isScheduled ? 'SCHEDULED' : 'DRAFT'),
            'parts' => array_map(static fn (ThemePartKind $part): array => ['part' => $part->value, 'filled' => in_array($part->value, $filled, true)], ThemePartKind::cases()),
            'dates' => array_map(static function (CampaignRun $run) use ($theme): array {
                $moments = $theme->stores[$run->storeId] ?? null;

                return ['storeId' => $run->storeId, 'match' => $moments !== null && $moments[0] == $run->startsAt && $moments[1] == $run->endsAt];
            }, array_values(array_filter($runs, static fn (CampaignRun $run): bool => ! $run->state->isOver()))),
        ];
    }

    /**
     * The themes on offer, the linked one kept though it has ended.
     *
     * @return list<array{id: string, nameAr: string, nameEn: string|null}>
     */
    private function themeChoices(?string $linked, \DateTimeImmutable $now): array
    {
        $choices = $this->reads->themeChoices($now);

        if ($linked !== null && array_filter($choices, static fn (array $theme): bool => $theme['id'] === $linked) === []) {
            $theme = $this->themes->find($linked);

            if ($theme !== null) {
                $choices[] = ['id' => $theme->id, 'nameAr' => $theme->nameAr, 'nameEn' => $theme->nameEn];
            }
        }

        return $choices;
    }
}
