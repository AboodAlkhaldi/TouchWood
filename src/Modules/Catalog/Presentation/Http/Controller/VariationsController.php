<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Catalog\Application\Command\ActivateAttributeSet\ActivateAttributeSet;
use Modules\Catalog\Application\Command\ActivateAttributeSet\ActivateAttributeSetHandler;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSet;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSetHandler;
use Modules\Catalog\Application\Command\DeactivateAttributeSet\DeactivateAttributeSet;
use Modules\Catalog\Application\Command\DeactivateAttributeSet\DeactivateAttributeSetHandler;
use Modules\Catalog\Application\Command\DeleteAttributeSet\DeleteAttributeSet;
use Modules\Catalog\Application\Command\DeleteAttributeSet\DeleteAttributeSetHandler;
use Modules\Catalog\Application\Command\EditAttributeSet\EditAttributeSet;
use Modules\Catalog\Application\Command\EditAttributeSet\EditAttributeSetHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ListPages;

/**
 * The variations screen (catalog.md §1.7, §4.4 S4): the attribute sets and their changes, under the
 * attributes' job with All stores (§3).
 */
final readonly class VariationsController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_attributes', 'admin'];

    private const array FIELDS = ['name_ar', 'name_en', 'attribute_ids'];

    // What asks who is acting - the pages, the store choices - is taken by each action, never kept here:
    // a controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(ListPages $pages): Response
    {
        return $this->page->render('Catalog/Admin/Variations/Index', $pages->variations()->toArray(), self::WORDS);
    }

    public function add(CatalogFormRequest $request, AddAttributeSetHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AddAttributeSet(
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->texts('attribute_ids'),
        )), 'catalog::admin_attributes.toast.variation_added', self::FIELDS);
    }

    public function edit(CatalogFormRequest $request, string $set, EditAttributeSetHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new EditAttributeSet(
            $set,
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->texts('attribute_ids'),
        )), 'catalog::admin_attributes.toast.variation_edited', self::FIELDS);
    }

    public function activate(Request $request, string $set, ActivateAttributeSetHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ActivateAttributeSet($set)), 'catalog::admin_attributes.toast.variation_activated');
    }

    public function deactivate(Request $request, string $set, DeactivateAttributeSetHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeactivateAttributeSet($set)), 'catalog::admin_attributes.toast.variation_deactivated');
    }

    public function delete(Request $request, string $set, DeleteAttributeSetHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteAttributeSet($set)), 'catalog::admin_attributes.toast.variation_deleted');
    }
}
