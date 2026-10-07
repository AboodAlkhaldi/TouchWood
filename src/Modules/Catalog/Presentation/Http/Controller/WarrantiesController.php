<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Catalog\Application\Command\ActivateWarranty\ActivateWarranty;
use Modules\Catalog\Application\Command\ActivateWarranty\ActivateWarrantyHandler;
use Modules\Catalog\Application\Command\AddWarranty\AddWarranty;
use Modules\Catalog\Application\Command\AddWarranty\AddWarrantyHandler;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarranty;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarrantyHandler;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarranty;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarrantyHandler;
use Modules\Catalog\Application\Command\EditWarranty\EditWarranty;
use Modules\Catalog\Application\Command\EditWarranty\EditWarrantyHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ListPages;

/**
 * The warranties screen (catalog.md §1.9, §4.4 S6), under `catalog.warranty.manage` with All stores
 * (§3). "Lifetime" is no period.
 */
final readonly class WarrantiesController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_warranties', 'admin'];

    private const array FIELDS = ['name_ar', 'name_en', 'terms_ar', 'terms_en', 'period_months'];

    // What asks who is acting - the pages, the store choices - is taken by each action, never kept here:
    // a controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(ListPages $pages): Response
    {
        return $this->page->render('Catalog/Admin/Warranties/Index', $pages->warranties()->toArray(), self::WORDS);
    }

    public function add(CatalogFormRequest $request, AddWarrantyHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AddWarranty(
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->marks('terms_ar') ?? [],
            $request->marks('terms_en') ?? [],
            self::period($request),
        )), 'catalog::admin_warranties.toast.added', self::FIELDS);
    }

    public function edit(CatalogFormRequest $request, string $warranty, EditWarrantyHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new EditWarranty(
            $warranty,
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->marks('terms_ar') ?? [],
            $request->marks('terms_en') ?? [],
            self::period($request),
        )), 'catalog::admin_warranties.toast.edited', self::FIELDS);
    }

    public function activate(Request $request, string $warranty, ActivateWarrantyHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ActivateWarranty($warranty)), 'catalog::admin_warranties.toast.activated');
    }

    public function deactivate(Request $request, string $warranty, DeactivateWarrantyHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeactivateWarranty($warranty)), 'catalog::admin_warranties.toast.deactivated');
    }

    public function delete(Request $request, string $warranty, DeleteWarrantyHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteWarranty($warranty)), 'catalog::admin_warranties.toast.deleted');
    }

    /** "Lifetime" ticked is no period; otherwise the months typed, which the domain checks (1–600). */
    private static function period(CatalogFormRequest $request): ?int
    {
        return $request->boolean('lifetime') ? null : $request->number('period_months');
    }
}
