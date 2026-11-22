<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\Page;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use JsonException;
use Modules\Access\Application\Query\AdminShell\AdminShellForStaff;
use Modules\Access\Application\Query\StoresForStaff\StoresForStaff;
use Modules\Access\Presentation\Http\Resource\HomeCardBlock;
use Modules\Access\Presentation\Http\Resource\HomeFigureBlock;
use Modules\Access\Presentation\Http\Resource\HomePage;
use Modules\Access\Presentation\Http\Resource\HomePeriodBlock;
use Modules\Access\Presentation\Http\Resource\HomeRowBlock;
use Modules\Access\Presentation\Http\Resource\StoreOptionBlock;
use Modules\Platform\Public\Contracts\HomeCards;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeCardDto;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\HomeBand;
use Modules\Platform\Public\Enums\HomePeriod;
use Shared\Domain\ValueObject\StoreId;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The panel's home (frontend.md §2.2), since build 7 the admin dashboard (dashboard.md).
 *
 * The home shows the cards the modules register (platform.md §2.6) that this reader may see, for one
 * scope chosen in its own switcher (the owner, 2026-10-06; access.md amendment 64): **All Stores** -
 * first, for a reader whose reach covers every store for a card - or one of the reader's stores
 * (`/admin?store=sa`; a Super Admin's off stores too). The switcher changes the figures only. Each
 * card's words are put into the reader's language here, so the page needs no module's words of its
 * own.
 *
 * **The period** (dashboard.md §3.3) is in the address beside the store (`/admin?store=sa&period=month`), This
 * Month when none or another is asked (D9). The cards come in three bands (§2): the greeting row's buttons,
 * the period's figures, and Needs Attention.
 *
 * **What each card answered is kept 5 minutes** (dashboard.md §7, D11) per reader, store, period and language -
 * per reader, because a card may show a reader only the figures they hold (Stores and System) - so a warm home
 * reads the cache once, not every module. Who may see each card is asked again on every visit; only what an
 * offered card shows is kept. The page says when its oldest figure was read, and Refresh forgets them.
 */
final readonly class AdminPanelController
{
    /** How long a card's figures are kept (dashboard.md §7, D11). */
    public const int KEPT_SECONDS = 300;

    public function __construct(
        private Page $page,
        private Cache $cache,
    ) {}

    public function home(Request $request, HomeCards $cards, StoresForStaff $stores, StoreChoices $choices, AdminShellForStaff $shell): Response
    {
        [$chosen, $offersAllStores, $mine] = $this->scope($request, $cards, $stores);
        $period = self::period($request);
        $allStores = $chosen === null && $offersAllStores;
        $at = CarbonImmutable::now();
        $scope = $allStores ? HomeScope::allStores($period, $at) : ($chosen === null ? null : HomeScope::store($chosen->storeId(), $period, $at));
        [$bands, $readAt] = $scope === null ? [[], null] : $this->bands($cards, $shell, $scope, $chosen, $at);
        $zone = $chosen->timezone ?? $choices->baseTimezone();

        $page = new HomePage(
            $chosen?->code,
            $offersAllStores,
            array_map(static fn (StoreDto $store): StoreOptionBlock => new StoreOptionBlock($store->code, $store->name->in(app()->getLocale()), $store->isActive), $mine),
            [],
            $chosen?->timezone,
            $period->value,
            array_map(static fn (HomePeriod $one): HomePeriodBlock => new HomePeriodBlock($one->value, self::words("admin.home.period.{$one->value}")), HomePeriod::cases()),
            self::greeting($at->setTimezone(new DateTimeZone($zone)), $shell->forCurrentStaff()->firstName ?? ''),
            readAt: $readAt,
        );

        // The cards as the cache keeps them: plain arrays of HomeCardBlock's shape.
        $props = [
            ...$page->toArray(),
            'cards' => $bands[HomeBand::Attention->value] ?? [],
            'sections' => $bands[HomeBand::Overview->value] ?? [],
            'actions' => $bands[HomeBand::Greeting->value] ?? [],
        ];

        return $this->page->render('Admin/Home', $props, ['admin', 'access::auth']);
    }

    /**
     * What each card offered in the scope shows, by band: kept for 5 minutes (D11), read again when not kept - and
     * when the oldest of them was read.
     *
     * @return array{array<string, list<array<string, mixed>>>, string|null}
     */
    private function bands(HomeCards $cards, AdminShellForStaff $shell, HomeScope $scope, ?StoreDto $chosen, CarbonImmutable $at): array
    {
        $offered = $cards->offeredTo($chosen?->id, $scope->isAllStores());
        $kept = $this->kept($shell, $offered, $chosen, $scope->period);
        $found = $kept === [] ? [] : $this->cache->many(array_values($kept));
        $fresh = [];
        $bands = [];
        $readAt = null;

        foreach ($offered as $card) {
            $key = $kept["{$card->module}.{$card->key}"];
            $entry = $found[$key] ?? null;

            if (! self::isEntry($entry)) {
                $data = $cards->read($card, $scope);
                $entry = ['at' => $at->toIso8601String(), 'block' => $data === null ? null : $this->block($card, $data)];
                $fresh[$key] = $entry;
            }

            if ($entry['block'] !== null) {
                $bands[$card->band->value][] = $entry['block'];
                $readAt = $readAt === null || $entry['at'] < $readAt ? $entry['at'] : $readAt;
            }
        }

        if ($fresh !== []) {
            $this->cache->putMany($fresh, self::KEPT_SECONDS);
        }

        return [$bands, $readAt];
    }

    /**
     * Refresh (dashboard.md §7, D11): forgets what this reader's cards answered for the store and period asked,
     * then opens the home on them again.
     */
    public function refresh(Request $request, HomeCards $cards, StoresForStaff $stores, AdminShellForStaff $shell): RedirectResponse
    {
        [$chosen, $offersAllStores] = $this->scope($request, $cards, $stores);
        $period = self::period($request);
        $offered = $cards->offeredTo($chosen?->id, $chosen === null && $offersAllStores);
        $kept = $this->kept($shell, $offered, $chosen, $period);

        if ($kept !== []) {
            $this->cache->deleteMultiple(array_values($kept));
        }

        $query = array_filter(['store' => $chosen?->code, 'period' => $period->value]);

        return redirect('/admin?'.http_build_query($query));
    }

    /**
     * The store asked, whether All Stores is offered, and the reader's stores.
     *
     * @return array{StoreDto|null, bool, list<StoreDto>}
     */
    private function scope(Request $request, HomeCards $cards, StoresForStaff $stores): array
    {
        $mine = $stores->forCurrentStaff();
        $offersAllStores = $cards->offersAllStores();
        $asked = $request->input('store');
        // Anything but text (`?store[]=`) is no store asked for.
        $asked = is_string($asked) ? trim($asked) : '';
        $chosen = null;

        if ($asked !== '') {
            // One of theirs, or nothing to show: another store's figures are not this reader's.
            $chosen = $stores->byCode($asked) ?? throw new AccessDeniedHttpException;
        } elseif (! $offersAllStores) {
            // No All Stores for them: their first store, by the stores' own order.
            $chosen = $mine[0] ?? null;
        }

        return [$chosen, $offersAllStores, $mine];
    }

    /** The period asked; This Month for none or anything else (dashboard.md §3.3, D9). */
    private static function period(Request $request): HomePeriod
    {
        $asked = $request->input('period');

        return is_string($asked) ? (HomePeriod::tryFrom($asked) ?? HomePeriod::OPENS_ON) : HomePeriod::OPENS_ON;
    }

    /**
     * Where each offered card's figures are kept, by `{module}.{key}`: one entry per reader, scope, period,
     * language and card.
     *
     * @param  list<HomeCardDto>  $offered
     * @return array<string, string>
     */
    private function kept(AdminShellForStaff $shell, array $offered, ?StoreDto $chosen, HomePeriod $period): array
    {
        $staff = $shell->forCurrentStaff()->id ?? '';
        $scope = $chosen === null ? 'all' : StoreId::fromString($chosen->id)->value;
        $keys = [];

        foreach ($offered as $card) {
            $name = "{$card->module}.{$card->key}";
            $keys[$name] = 'access.home.'.sha1(implode('|', [$staff, $scope, $period->value, app()->getLocale(), $name]));
        }

        return $keys;
    }

    /** @phpstan-assert-if-true array{at: string, block: array<string, mixed>|null} $entry */
    private static function isEntry(mixed $entry): bool
    {
        return is_array($entry) && is_string($entry['at'] ?? null) && array_key_exists('block', $entry) && ($entry['block'] === null || is_array($entry['block']));
    }

    /**
     * "Good morning, Sara" (dashboard.md §2 A, D1): morning from 05:00 to 11:59, afternoon to 16:59, evening
     * otherwise, by the store's clock - the base store's for All Stores. Without a first name, the greeting alone.
     */
    private static function greeting(CarbonImmutable $now, string $firstName): string
    {
        $hour = (int) $now->format('G');
        $part = $hour >= 5 && $hour < 12 ? 'morning' : ($hour >= 12 && $hour < 17 ? 'afternoon' : 'evening');

        return $firstName === ''
            ? self::words("admin.home.greeting.{$part}")
            : (string) __("admin.home.greeting.{$part}_named", ['name' => $firstName]);
    }

    /**
     * One card as the page draws it, its words in the reader's language: an array of plain values, the shape the
     * cache keeps and the page reads.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function block(HomeCardDto $card, HomeCardData $data): array
    {
        $words = $card->wordsKey();
        $block = new HomeCardBlock(
            "{$card->module}.{$card->key}",
            self::words("{$words}.title"),
            array_map(static fn (HomeFigure $figure): HomeFigureBlock => new HomeFigureBlock(
                self::words("{$words}.{$figure->label}"),
                $figure->value,
                $figure->unit,
                $figure->href,
                $figure->tone,
            ), $data->figures),
            array_map(static fn (HomeRow $row): HomeRowBlock => new HomeRowBlock($row->label, $row->detail, $row->at, $row->href), $data->rows),
            $data->rowsLabel === null ? null : self::words("{$words}.{$data->rowsLabel}"),
            $data->href,
            // Geist's button names what happens, so each card says where it leads.
            $data->href === null ? null : self::words("{$words}.open"),
            $data->size->value,
            $data->metrics,
            $data->panels,
            $data->action,
        );

        /** @var array<string, mixed> */
        return json_decode(json_encode($block->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** One line of a card's words; a key that names a group rather than a line reads as itself. */
    private static function words(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
