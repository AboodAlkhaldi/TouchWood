<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Query\StoresForStaff\StoresForStaff;
use Modules\Access\Presentation\Http\Resource\HomeCardBlock;
use Modules\Access\Presentation\Http\Resource\HomeFigureBlock;
use Modules\Access\Presentation\Http\Resource\HomePage;
use Modules\Access\Presentation\Http\Resource\HomeRowBlock;
use Modules\Access\Presentation\Http\Resource\StoreOptionBlock;
use Modules\Platform\Public\Contracts\HomeCards;
use Modules\Platform\Public\Dto\HomeCardView;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\StoreDto;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The panel's home (frontend.md §2.2).
 *
 * The home shows the cards the modules register (platform.md §2.6) that this reader may see, for one
 * scope chosen in its own switcher (the owner, 2026-10-06; access.md amendment 64): **All Stores** -
 * first, for a reader whose reach covers every store for a card - or one of the reader's stores
 * (`/admin?store=sa`; a Super Admin's off stores too). The switcher changes the figures only. Each
 * card's words are put into the reader's language here, so the page needs no module's words of its
 * own.
 */
final readonly class AdminPanelController
{
    public function __construct(
        private Page $page,
    ) {}

    public function home(Request $request, HomeCards $cards, StoresForStaff $stores): Response
    {
        $mine = $stores->forCurrentStaff();
        $offersAllStores = $cards->offersAllStores();
        $asked = $request->query('store');
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

        $page = new HomePage(
            $chosen?->code,
            $offersAllStores,
            array_map(static fn (StoreDto $store): StoreOptionBlock => new StoreOptionBlock($store->code, $store->name->in(app()->getLocale()), $store->isActive), $mine),
            array_map($this->block(...), $cards->forCurrentActor($chosen?->id, $chosen === null && $offersAllStores)),
            $chosen?->timezone,
        );

        return $this->page->render('Admin/Home', $page->toArray(), ['admin', 'access::auth']);
    }

    private function block(HomeCardView $view): HomeCardBlock
    {
        $words = $view->card->wordsKey();

        return new HomeCardBlock(
            "{$view->card->module}.{$view->card->key}",
            self::words("{$words}.title"),
            array_map(static fn (HomeFigure $figure): HomeFigureBlock => new HomeFigureBlock(
                self::words("{$words}.{$figure->label}"),
                $figure->value,
                $figure->unit,
                $figure->href,
                $figure->tone,
            ), $view->data->figures),
            array_map(static fn (HomeRow $row): HomeRowBlock => new HomeRowBlock($row->label, $row->detail, $row->at, $row->href), $view->data->rows),
            $view->data->rowsLabel === null ? null : self::words("{$words}.{$view->data->rowsLabel}"),
            $view->data->href,
            // Geist's button names what happens, so each card says where it leads.
            $view->data->href === null ? null : self::words("{$words}.open"),
        );
    }

    /** One line of a card's words; a key that names a group rather than a line reads as itself. */
    private static function words(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
