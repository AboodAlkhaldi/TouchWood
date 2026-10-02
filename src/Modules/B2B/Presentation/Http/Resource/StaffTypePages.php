<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use App\Http\PanelStore;
use Illuminate\Contracts\Foundation\Application;
use Modules\B2B\Application\Query\ViewTypeLists\StaffTypeView;
use Modules\B2B\Application\Query\ViewTypeLists\ViewTypeLists;
use Modules\B2B\Application\Query\ViewTypeLists\ViewTypeListsHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * The types page (b2b.md §1.3, §4.6), for **the store in the panel's header** — never a store from
 * the request (frontend.md §2.2). Who may read which list is B2B's answer (ViewTypeLists); this
 * names the store and carries the rest.
 */
final readonly class StaffTypePages
{
    public function __construct(
        private Application $app,
        private PanelStore $panel,
        private PlatformApi $platform,
        private ViewTypeListsHandler $lists,
    ) {}

    public function page(string $kind): StaffTypeListPage
    {
        $view = $this->lists->handle(new ViewTypeLists($this->panel->id(), $kind));
        $store = $this->platform->store(StoreId::fromString($view->storeId));
        $actions = $view->actions;

        return new StaffTypeListPage(
            kind: $view->kind,
            storeName: $store === null ? $view->storeId : $store->name->in($this->app->getLocale()),
            copiedNotReviewed: $view->copiedNotReviewed,
            types: array_map(
                static fn (StaffTypeView $type): StaffTypeRowData => new StaffTypeRowData(
                    $type->id, $type->nameAr, $type->nameEn, $type->position, $type->active,
                    $type->inactiveDisplay, $type->required, $type->holders,
                ),
                $view->types,
            ),
            actions: new StaffTypeActionsData(
                $actions->mayReadCompanyTypes,
                $actions->mayReadDocumentTypes,
                $actions->mayAdd,
                $actions->mayUpdate,
                $actions->mayDeactivate,
                $actions->mayDeactivateIntoNew,
                $actions->mayTransfer,
                $actions->mayMarkReviewed,
            ),
        );
    }

    /**
     * The store the panel is working in, for the actions that name one (adding a type, "Reviewed").
     */
    public function storeId(): string
    {
        return $this->panel->id() ?? '';
    }
}
