<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use App\Http\FormErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use JsonException;
use Modules\Content\Application\Command\CancelCampaign\CancelCampaign;
use Modules\Content\Application\Command\CancelCampaign\CancelCampaignHandler;
use Modules\Content\Application\Command\ChooseRunNotice\ChooseRunNotice;
use Modules\Content\Application\Command\ChooseRunNotice\ChooseRunNoticeHandler;
use Modules\Content\Application\Command\DeleteCampaign\DeleteCampaign;
use Modules\Content\Application\Command\DeleteCampaign\DeleteCampaignHandler;
use Modules\Content\Application\Command\EndCampaign\EndCampaign;
use Modules\Content\Application\Command\EndCampaign\EndCampaignHandler;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaign;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaignHandler;
use Modules\Content\Application\Command\SetRunPrices\SetRunPrices;
use Modules\Content\Application\Command\SetRunPrices\SetRunPricesHandler;
use Modules\Content\Application\Command\UseCampaignDates\UseCampaignDates;
use Modules\Content\Application\Command\UseCampaignDates\UseCampaignDatesHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\Campaigns\CampaignsHandler;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\PreviewRunPrices\PreviewRunPricesHandler;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Exception\CampaignPartlyScheduled;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Presentation\Http\Resource\CampaignAddressData;
use Modules\Content\Presentation\Http\Resource\CampaignData;
use Modules\Content\Presentation\Http\Resource\CampaignFormPageData;
use Modules\Content\Presentation\Http\Resource\CampaignNoticeData;
use Modules\Content\Presentation\Http\Resource\CampaignOfferData;
use Modules\Content\Presentation\Http\Resource\CampaignPreviewData;
use Modules\Content\Presentation\Http\Resource\CampaignPreviewProductData;
use Modules\Content\Presentation\Http\Resource\CampaignPreviewSizeData;
use Modules\Content\Presentation\Http\Resource\CampaignPricesData;
use Modules\Content\Presentation\Http\Resource\CampaignRowData;
use Modules\Content\Presentation\Http\Resource\CampaignRunData;
use Modules\Content\Presentation\Http\Resource\CampaignsPageData;
use Modules\Content\Presentation\Http\Resource\CampaignTargetData;
use Modules\Content\Presentation\Http\Resource\CampaignThemeData;
use Modules\Content\Presentation\Http\Resource\ContentStoreData;
use Modules\Content\Presentation\Http\Resource\NamedChoiceData;
use Modules\Content\Presentation\Http\Resource\RunStateData;
use Modules\Content\Presentation\Http\Resource\ThemeDateMatchData;
use Modules\Content\Presentation\Http\Resource\ThemePartFillData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Public\Dto\CampaignOfferDto;
use Modules\Pricing\Public\Dto\CampaignPreviewProductDto;
use Modules\Pricing\Public\Dto\CampaignPreviewSizeDto;
use Shared\Domain\Error\DomainError;

/*
| W4 · Campaigns and W4b, a campaign's page (content.md §4.4, §1.7): the store's list by its run's state; New Campaign and
| each campaign's page - its shared fields, its theme link, each store's prices (with Pricing's preview, read as they are
| typed), each store's notice - and what its menu does: Schedule, End, Cancel (all stores or one, named), Delete a draft,
| a Super Admin's Use Campaign's Dates. Under `content.campaign.manage`, which each handler asks again, in every store a
| shared field needs. A refusal goes back to its box when the form has it, else to the form's top with the sizes it names
| marked (`FormErrors::back`, `rows.*`): what was typed stays on the screen.
*/
final readonly class CampaignsController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_campaigns'];

    /** @var list<string> the form names a linked theme's parts in the themes' own words */
    private const array FORM_WORDS = ['content::admin_campaigns', 'content::admin_themes'];

    /** @var list<string> */
    private const array BOXES = ['name_ar', 'name_en', 'starts_at', 'ends_at', 'stores', 'theme'];

    private const string LIST = '/admin/content/campaigns';

    /** @var list<string> */
    private const array TABS = ['all', 'scheduled', 'live', 'ended'];

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, CampaignsHandler $campaigns, PlatformApi $platform): Response|JsonResponse
    {
        $store = $this->screens->store($request, ContentPermissions::CAMPAIGN_MANAGE);
        $tab = in_array(Inputs::text($request, 'tab'), self::TABS, true) ? (string) Inputs::text($request, 'tab') : 'all';
        $read = $campaigns->list($store->id, $tab === 'all' ? null : $tab, Inputs::text($request, 'after'));
        $codes = self::codes($platform);
        $rows = array_map(static fn (array $row): CampaignRowData => self::row($row, $codes), $read['rows']);
        $next = count($rows) === ScreenReads::PAGE ? $rows[count($rows) - 1]->id : null;

        if ($request->expectsJson()) {
            return new JsonResponse(['campaigns' => array_map(static fn (CampaignRowData $row): array => $row->toArray(), $rows), 'next' => $next]);
        }

        return $this->screens->render('Content/Admin/Campaigns/Index', (new CampaignsPageData(
            $this->screens->stores(ContentPermissions::CAMPAIGN_MANAGE),
            $store->code,
            $store->timezone,
            $tab,
            $read['counts'],
            $rows,
            $next,
        ))->toArray(), self::WORDS);
    }

    public function create(CampaignsHandler $campaigns, ContentActionsForReader $actions): Response
    {
        return $this->screens->render('Content/Admin/Campaigns/Form', (new CampaignFormPageData(
            $this->screens->stores(ContentPermissions::CAMPAIGN_MANAGE),
            null,
            [],
            null,
            array_map(self::choice(...), $campaigns->themesOnOffer()),
            $actions->mayManageThemes(),
            $actions->translationReady(),
        ))->toArray(), self::FORM_WORDS);
    }

    public function edit(string $campaign, CampaignsHandler $campaigns, ContentActionsForReader $actions): Response
    {
        $read = $campaigns->page($campaign);
        $runs = array_map(self::runData(...), $read['runs']);
        $states = array_values(array_unique(array_map(static fn (CampaignRunData $run): string => $run->state, $runs)));
        $theme = $read['theme'];

        return $this->screens->render('Content/Admin/Campaigns/Form', (new CampaignFormPageData(
            $this->screens->stores(ContentPermissions::CAMPAIGN_MANAGE),
            new CampaignData(
                $read['campaign']->id,
                $read['campaign']->nameAr,
                $read['campaign']->nameEn,
                $read['campaign']->startsLocal,
                $read['campaign']->endsLocal,
                $read['campaign']->themeId,
                $read['campaign']->version,
                count($states) === 1 ? $states[0] : 'MIXED',
                // Half live (§1.7 #8): a run has gone live, whatever it is now.
                array_filter($read['runs'], static fn (array $run): bool => $run['wentLive'] === true) !== [],
                $read['unread'],
            ),
            $runs,
            $theme === null ? null : new CampaignThemeData(
                (string) $theme['id'],
                (string) $theme['nameAr'],
                is_string($theme['nameEn']) ? $theme['nameEn'] : null,
                (string) $theme['startsLocal'],
                (string) $theme['endsLocal'],
                (string) $theme['state'],
                array_values(array_map(static fn (array $part): ThemePartFillData => new ThemePartFillData((string) $part['part'], (bool) $part['filled']), (array) $theme['parts'])),
                array_values(array_map(static fn (array $date): ThemeDateMatchData => new ThemeDateMatchData((string) $date['storeId'], (bool) $date['match']), (array) $theme['dates'])),
            ),
            array_map(self::choice(...), $read['themes']),
            $actions->mayManageThemes(),
            $actions->translationReady(),
        ))->toArray(), self::FORM_WORDS);
    }

    /** A run's live preview, as its prices are typed (§1.7 #6). */
    public function preview(Request $request, PreviewRunPricesHandler $preview, PlatformApi $platform): JsonResponse
    {
        $answer = $preview->handle((string) Inputs::text($request, 'run'), self::priceInput($request), Inputs::text($request, 'after'));
        $exponents = [];

        foreach ($platform->allStores() as $store) {
            $exponents[$store->currencyCode] = $store->currencyExponent;
        }

        return new JsonResponse((new CampaignPreviewData(
            array_map(static fn (CampaignPreviewProductDto $product): CampaignPreviewProductData => self::previewProduct($product, $exponents), $answer->products),
            $answer->next,
            $answer->reachedProducts,
            $answer->reachedSizes,
            $answer->skippedSizes,
            $answer->deepestBasisPoints,
            array_map(static fn (CampaignOfferDto $offer): CampaignOfferData => new CampaignOfferData($offer->kind->value, strtolower($offer->id), $offer->startsAt->format(DATE_ATOM), $offer->endsAt?->format(DATE_ATOM), $offer->alwaysWins, count($offer->variantIds)), $answer->otherOffers),
            self::previewCurrency($answer->products),
        ))->toArray());
    }

    /** What a run's price may name: products and sizes the store sells, categories and brands switched on. */
    public function targets(Request $request, CampaignsHandler $campaigns): JsonResponse
    {
        return new JsonResponse($campaigns->targets((string) Inputs::text($request, 'run'), (string) Inputs::text($request, 'kind'), Inputs::text($request, 'q'), Inputs::text($request, 'after')));
    }

    public function store(Request $request, SaveCampaignHandler $save, ScheduleCampaignHandler $schedule, CampaignsHandler $campaigns): RedirectResponse
    {
        return $this->save($request, null, $save, $schedule, $campaigns);
    }

    public function update(Request $request, string $campaign, SaveCampaignHandler $save, ScheduleCampaignHandler $schedule, CampaignsHandler $campaigns): RedirectResponse
    {
        return $this->save($request, $campaign, $save, $schedule, $campaigns);
    }

    /** Schedule Campaign (§1.7 #14, #15): every draft run, or one store's; a partial schedule says which stores. */
    public function schedule(Request $request, string $campaign, ScheduleCampaignHandler $schedule, CampaignsHandler $campaigns): RedirectResponse
    {
        try {
            $schedule->handle(new ScheduleCampaign($campaign, Inputs::version($request), Inputs::text($request, 'store'), Inputs::flag($request, 'read')));
        } catch (DomainError $error) {
            return self::refused($request, $error, $campaigns, null, $campaign, Inputs::text($request, 'store'));
        }

        return back()->with('status', __('content::admin_campaigns.scheduled'));
    }

    public function prices(Request $request, string $run, SetRunPricesHandler $prices, CampaignsHandler $campaigns): RedirectResponse
    {
        try {
            $prices->handle(new SetRunPrices($run, Inputs::version($request), $request->input('prices') === null ? null : self::priceInput($request)));
        } catch (DomainError $error) {
            return self::refused($request, $error, $campaigns, $run);
        }

        return back()->with('status', __('content::admin_campaigns.prices_saved'));
    }

    /**
     * A refusal on W4b: under its box, or at the top (Refusals). One that names sizes - Pricing's, as a schedule's or a
     * prices' save - says too **which store's** they are (`errors.rows_store`), so only that store's Prices tab marks
     * them: the same size in two stores is two rows (PR 8's review).
     */
    private static function refused(Request $request, DomainError $error, CampaignsHandler $campaigns, ?string $runId, ?string $campaignId = null, ?string $storeId = null): RedirectResponse
    {
        $rows = FormErrors::rows($error);

        if ($rows === [] || $request->expectsJson()) {
            return Refusals::back($request, $error, self::BOXES);
        }

        $store = $error instanceof CampaignPartlyScheduled ? $error->storeId : $campaigns->refusedStore($runId, $campaignId, $storeId);
        $errors = ['form' => FormErrors::message($error), 'rows_store' => (string) $store];

        foreach ($rows as $row => $message) {
            $errors["rows.{$row}"] = $message;
        }

        return back()->withErrors($errors);
    }

    public function notice(Request $request, string $run, ChooseRunNoticeHandler $notice): RedirectResponse
    {
        return self::act($request, fn () => $notice->handle(new ChooseRunNotice($run, Inputs::version($request), Inputs::text($request, 'notice'))), 'content::admin_campaigns.notice_saved');
    }

    public function cancel(Request $request, string $campaign, CancelCampaignHandler $cancel): RedirectResponse
    {
        return self::act($request, fn () => $cancel->handle(new CancelCampaign($campaign, Inputs::text($request, 'store'))), 'content::admin_campaigns.cancelled');
    }

    public function end(Request $request, string $campaign, EndCampaignHandler $end): RedirectResponse
    {
        return self::act($request, fn () => $end->handle(new EndCampaign($campaign, Inputs::text($request, 'store'))), 'content::admin_campaigns.ended');
    }

    public function themeDates(Request $request, string $campaign, UseCampaignDatesHandler $dates): RedirectResponse
    {
        return self::act($request, fn () => $dates->handle(new UseCampaignDates($campaign, Inputs::version($request))), 'content::admin_campaigns.dates_used');
    }

    public function delete(Request $request, string $campaign, DeleteCampaignHandler $delete): RedirectResponse
    {
        try {
            $delete->handle(new DeleteCampaign($campaign, Inputs::version($request)));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return redirect(self::LIST)->with('status', __('content::admin_campaigns.deleted'));
    }

    /**
     * Save Draft, or Schedule Campaign from the form: saved, then every draft run scheduled at the version the save made.
     * **Two steps, not one transaction**: Schedule writes each store's run in its own (§1.7 #15), so it cannot share the
     * save's. A Schedule refused after the save leaves the save standing, and says so in words beside the refusal (a new
     * campaign's on its own page, which now exists); the form then takes what was saved as its own.
     */
    private function save(Request $request, ?string $id, SaveCampaignHandler $save, ScheduleCampaignHandler $schedule, CampaignsHandler $campaigns): RedirectResponse
    {
        $version = Inputs::version($request);

        try {
            $saved = $save->handle(new SaveCampaign(
                $id,
                $version,
                Inputs::typed($request, 'name_ar'),
                Inputs::typed($request, 'name_en'),
                Inputs::text($request, 'starts_at'),
                Inputs::text($request, 'ends_at'),
                Inputs::texts($request, 'stores'),
                Inputs::text($request, 'theme'),
                Inputs::flag($request, 'translated'),
                Inputs::flag($request, 'read'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BOXES);
        }

        if (Inputs::text($request, 'intent') === 'schedule') {
            try {
                // The save raised the version by one: a new campaign's is 1, a changed one's the form's plus one.
                $schedule->handle(new ScheduleCampaign($saved, $id === null ? 1 : $version + 1, null, Inputs::flag($request, 'read')));
            } catch (DomainError $error) {
                $refused = $id === null ? Refusals::to(self::LIST."/{$saved}", $error, self::BOXES) : self::refused($request, $error, $campaigns, null, $saved);

                return $refused->with('status', __('content::admin_campaigns.saved_not_scheduled'));
            }

            return redirect(self::LIST."/{$saved}")->with('status', __('content::admin_campaigns.scheduled'));
        }

        return redirect(self::LIST."/{$saved}")->with('status', __('content::admin_campaigns.saved'));
    }

    /**
     * A run's prices as the page sends them: `prices` a JSON text (a preview's query) or an object (a save's body).
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidContentInput
     */
    private static function priceInput(Request $request): array
    {
        $value = $request->input('prices');

        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidContentInput('targets', InvalidContentInput::FORMAT);
            }
        }

        if (! is_array($value)) {
            throw new InvalidContentInput('targets', InvalidContentInput::FORMAT);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $codes  store id => code
     */
    private static function row(array $row, array $codes): CampaignRowData
    {
        /** @var list<array{runId: string, storeId: string, state: string, offers: int|null}> $runs */
        $runs = $row['runs'];
        $states = array_values(array_unique(array_map(static fn (array $run): string => $run['state'], $runs)));
        /** @var array{nameAr: string, nameEn: string|null}|null $theme */
        $theme = $row['theme'];
        /** @var array{titleAr: string, titleEn: string, status: string}|null $notice */
        $notice = $row['notice'];

        return new CampaignRowData(
            (string) $row['id'],
            (string) $row['nameAr'],
            is_string($row['nameEn']) ? $row['nameEn'] : null,
            (string) $row['runId'],
            (string) $row['state'],
            count($states) === 1 ? $states[0] : 'MIXED',
            is_string($row['startsAt']) ? $row['startsAt'] : null,
            is_string($row['endsAt']) ? $row['endsAt'] : null,
            array_map(static fn (array $run): RunStateData => new RunStateData($run['storeId'], $codes[$run['storeId']] ?? '', $run['state'], $run['offers']), $runs),
            $theme['nameAr'] ?? null,
            $theme['nameEn'] ?? null,
            $notice === null || ! is_string($row['noticeId']) ? null : new CampaignNoticeData($row['noticeId'], $notice['titleAr'], $notice['titleEn'], $notice['status']),
            (int) $row['version'],
        );
    }

    /**
     * The campaign page's full web address in each language (the owner, 2026-11-12), as the shop's canonical tags write
     * them: the site's root - APP_URL, whose host is the canonical one (content.md §1.14, L5; the sitemap's too) - and the
     * shop's own route, never a typed domain. None while the run has no page that can answer.
     *
     * @param  array<array-key, mixed>|null  $slugs
     * @return list<CampaignAddressData>
     */
    private static function addresses(StoreDto $store, ?array $slugs): array
    {
        if ($slugs === null) {
            return [];
        }

        $root = rtrim((string) config('app.url'), '/');
        $addresses = [];

        foreach (['ar', 'en'] as $locale) {
            if (is_string($slugs[$locale] ?? null)) {
                $addresses[] = new CampaignAddressData($locale, $root.route('storefront.content.campaign', ['store' => $store->code, 'locale' => $locale, 'slug' => $slugs[$locale]], false));
            }
        }

        return $addresses;
    }

    /**
     * @param  array<string, mixed>  $run  CampaignsHandler::page()'s
     */
    private static function runData(array $run): CampaignRunData
    {
        /** @var StoreDto $store */
        $store = $run['store'];
        /** @var array{lines: string, targets: list<array<string, mixed>>, leftOut: list<array<string, mixed>>}|null $prices */
        $prices = $run['prices'];
        /** @var array{id: string, titleAr: string|null, titleEn: string|null, status: string}|null $notice */
        $notice = $run['notice'];
        /** @var list<array<string, mixed>> $skipped */
        $skipped = $run['skipped'];
        /** @var list<array{id: string, titleAr: string, titleEn: string}> $drafts */
        $drafts = $run['drafts'];

        return new CampaignRunData(
            (string) $run['id'],
            ContentStoreData::of($store),
            (string) $run['state'],
            (string) $run['startsAt'],
            (string) $run['endsAt'],
            (bool) $run['pricesOpen'],
            (bool) $run['mayPrice'],
            (bool) $run['mayNotices'],
            is_int($run['ceilingPercent']) ? $run['ceilingPercent'] : null,
            $store->currencyCode,
            $store->currencyExponent,
            $prices === null ? null : new CampaignPricesData(
                $prices['lines'],
                array_map(self::target(...), $prices['targets']),
                array_map(static fn (array $target): CampaignTargetData => self::target(['kind' => 'PRODUCT', ...$target]), $prices['leftOut']),
            ),
            array_map(static fn (array $size): CampaignTargetData => self::target(['kind' => 'SIZE', ...$size]), $skipped),
            $notice === null ? null : new CampaignNoticeData($notice['id'], $notice['titleAr'], $notice['titleEn'], $notice['status']),
            array_map(static fn (array $draft): CampaignNoticeData => new CampaignNoticeData($draft['id'], $draft['titleAr'], $draft['titleEn'], 'DRAFT'), $drafts),
            self::addresses($store, is_array($run['slugs'] ?? null) ? $run['slugs'] : null),
        );
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function target(array $target): CampaignTargetData
    {
        return new CampaignTargetData(
            (string) $target['kind'],
            (string) $target['id'],
            (string) $target['nameAr'],
            is_string($target['nameEn'] ?? null) ? $target['nameEn'] : null,
            is_string($target['detail'] ?? null) ? $target['detail'] : null,
            (bool) ($target['archived'] ?? false),
            is_int($target['percentBp'] ?? null) ? $target['percentBp'] : null,
            is_int($target['amountMinor'] ?? null) ? $target['amountMinor'] : null,
        );
    }

    /**
     * @param  array{id: string, nameAr: string, nameEn: string|null}  $choice
     */
    private static function choice(array $choice): NamedChoiceData
    {
        return new NamedChoiceData($choice['id'], $choice['nameAr'], $choice['nameEn']);
    }

    /**
     * @param  array<string, int>  $exponents  currency => its decimals, as the stores keep them
     */
    private static function previewProduct(CampaignPreviewProductDto $product, array $exponents): CampaignPreviewProductData
    {
        return new CampaignPreviewProductData(strtolower($product->productId), $product->nameAr, $product->nameEn, array_map(static fn (CampaignPreviewSizeDto $size): CampaignPreviewSizeData => new CampaignPreviewSizeData(
            strtolower($size->variantId),
            $size->code,
            $size->retail?->toDecimal($exponents[$size->retail->currencyCode] ?? 2),
            $size->newPrice?->toDecimal($exponents[$size->newPrice->currencyCode] ?? 2),
            $size->depthBasisPoints,
            $size->skipped,
        ), $product->sizes));
    }

    /**
     * The currency the preview's prices are in: its first priced size's ('' with none).
     *
     * @param  list<CampaignPreviewProductDto>  $products
     */
    private static function previewCurrency(array $products): string
    {
        foreach ($products as $product) {
            foreach ($product->sizes as $size) {
                if ($size->retail !== null) {
                    return $size->retail->currencyCode;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, string> store id => code
     */
    private static function codes(PlatformApi $platform): array
    {
        $codes = [];

        foreach ($platform->allStores() as $store) {
            $codes[strtolower($store->id)] = $store->code;
        }

        return $codes;
    }

    /**
     * @param  callable(): mixed  $change
     */
    private static function act(Request $request, callable $change, string $toast): RedirectResponse
    {
        try {
            $change();
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($toast));
    }
}
