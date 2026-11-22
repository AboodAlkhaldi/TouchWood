<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\B2B\Application\Query\ViewTypeLists\StaffTypeView;
use Modules\B2B\Application\Query\ViewTypeLists\ViewTypeLists;
use Modules\B2B\Application\Query\ViewTypeLists\ViewTypeListsHandler;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * The types page (b2b.md §1.3, §4.6), for **the store chosen in its own filter** (`?store=sa`;
 * amendment 30, the owner 2026-10-06 - the panel no longer has a store worked in): the stores where
 * the reader holds a job on the list, a Super Admin's off ones marked Off. Who may read which list is
 * B2B's answer (ViewTypeLists), asked again for the store chosen; this names the store and carries
 * the rest.
 */
final readonly class StaffTypePages
{
    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private ViewTypeListsHandler $lists,
    ) {}

    /**
     * @throws Unauthorized when a store is asked for that the reader may not choose here
     */
    public function page(string $kind, ?string $storeCode): StaffTypeListPage
    {
        $jobs = self::jobs($kind);
        $chosen = $this->choices->chosen($storeCode, ...$jobs);
        $view = $this->lists->handle(new ViewTypeLists($chosen?->id, $kind));
        $actions = $view->actions;

        return new StaffTypeListPage(
            kind: $view->kind,
            storeName: $chosen === null ? $view->storeId : $chosen->name->in($this->locale()),
            storeCode: $chosen->code ?? '',
            stores: array_map(
                fn (StoreDto $store): StaffStoreOptionData => new StaffStoreOptionData($store->id, $store->code, $store->name->in($this->locale()), $store->isActive),
                $this->choices->forJobs(...$jobs),
            ),
            storeTimezone: $chosen?->timezone,
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
     * The store an action that names one acts in (adding a type, "Reviewed"): the one the page sent,
     * if the reader may choose it on the page of one of these kinds - the handler then asks for its
     * own job there. It is never filled in: a form that names no store (an old tab, a hand-made
     * request) is refused, not sent to the first store (amendment 30: Add and Reviewed send it).
     *
     * @param  list<string>  $kinds
     *
     * @throws Unauthorized when it names none, or one that is not theirs
     */
    public function storeId(array $kinds, ?string $storeCode): string
    {
        $jobs = array_values(array_unique(array_merge(...array_map(self::jobs(...), $kinds))));

        if ($storeCode === null || trim($storeCode) === '') {
            throw new Unauthorized($jobs[0] ?? 'store');
        }

        return $this->choices->chosen($storeCode, ...$jobs)->id ?? '';
    }

    /**
     * @return list<string>
     */
    private static function jobs(string $kind): array
    {
        return $kind === ViewTypeLists::COMPANY ? ViewTypeListsHandler::COMPANY_JOBS : ViewTypeListsHandler::DOCUMENT_JOBS;
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
