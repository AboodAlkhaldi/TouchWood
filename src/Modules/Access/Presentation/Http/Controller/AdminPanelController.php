<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\PanelStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStore;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStoreHandler;
use Modules\Access\Presentation\Http\Resource\HomeCardBlock;
use Modules\Access\Presentation\Http\Resource\HomeFigureBlock;
use Modules\Access\Presentation\Http\Resource\HomePage;
use Modules\Access\Presentation\Http\Resource\HomeRowBlock;
use Modules\Platform\Public\Contracts\HomeCards;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\HomeCardView;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * The panel itself: where it opens, and the two choices a person makes about it (frontend.md §2.2).
 *
 * The home shows the cards the modules register (platform.md §2.6) that this reader may see, for
 * one scope: All Stores - first, for a reader whose reach covers every store for a card (the owner,
 * 2026-10-05) - or the store being worked in. Each card's words are put into the reader's language
 * here, so the page needs no module's words of its own.
 */
final readonly class AdminPanelController
{
    public function __construct(
        private Page $page,
    ) {}

    public function home(Request $request, HomeCards $cards, PanelStore $panelStore): Response
    {
        $storeWorkedIn = $panelStore->id();
        $offersAllStores = $cards->offersAllStores();
        // All Stores first when it is offered; This Store only when asked for, and there is one.
        $allStores = $offersAllStores && ($request->query('scope') !== 'store' || $storeWorkedIn === null);

        $page = new HomePage(
            $allStores ? 'all' : 'store',
            // A switch needs two sides: All Stores, and a store being worked in.
            $offersAllStores && $storeWorkedIn !== null,
            array_map($this->block(...), $cards->forCurrentActor($storeWorkedIn, $allStores)),
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

    /**
     * Which store the panel is working in. A preference, never a permission: the handler refuses any
     * store that is not theirs, and every screen still scopes itself by what they may do.
     */
    public function chooseStore(Request $request, ChooseCurrentStoreHandler $handler, PlatformApi $platform): RedirectResponse
    {
        $storeId = $request->string('store')->toString();

        try {
            $handler->handle(new ChooseCurrentStore($storeId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['store']);
        }

        $store = $platform->store(StoreId::fromString($storeId));

        return back()->with('status', __('admin.store.changed', [
            // The handler above refused anything that is not theirs, so the store is real here;
            // the name is still read defensively, because a message is not worth an error page.
            'store' => $store === null ? '' : $store->name->in(app()->getLocale()),
        ]));
    }
}
