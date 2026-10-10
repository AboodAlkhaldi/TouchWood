<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Catalog\Application\Command\ActivateLabel\ActivateLabel;
use Modules\Catalog\Application\Command\ActivateLabel\ActivateLabelHandler;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabel;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabelHandler;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabel;
use Modules\Catalog\Application\Command\DeleteLabel\DeleteLabelHandler;
use Modules\Catalog\Application\Command\EditLabel\EditLabel;
use Modules\Catalog\Application\Command\EditLabel\EditLabelHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ListPages;

/**
 * The labels screen (catalog.md §1.8, §4.4 S5): «الشارات», under `catalog.label.manage` with All
 * stores (§3).
 */
final readonly class LabelsController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_labels', 'admin'];

    private const array FIELDS = ['name_ar', 'name_en', 'tone', 'position'];

    // What asks who is acting - the pages, the store choices - is taken by each action, never kept here:
    // a controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(ListPages $pages): Response
    {
        return $this->page->render('Catalog/Admin/Labels/Index', $pages->labels()->toArray(), self::WORDS);
    }

    public function add(CatalogFormRequest $request, AddLabelHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AddLabel(
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->text('tone'),
            $request->number('position'),
        )), 'catalog::admin_labels.toast.added', self::FIELDS);
    }

    public function edit(CatalogFormRequest $request, string $label, EditLabelHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new EditLabel(
            $label,
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->text('tone'),
            $request->number('position'),
        )), 'catalog::admin_labels.toast.edited', self::FIELDS);
    }

    public function activate(Request $request, string $label, ActivateLabelHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ActivateLabel($label)), 'catalog::admin_labels.toast.activated');
    }

    public function deactivate(Request $request, string $label, DeactivateLabelHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeactivateLabel($label)), 'catalog::admin_labels.toast.deactivated');
    }

    public function delete(Request $request, string $label, DeleteLabelHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteLabel($label)), 'catalog::admin_labels.toast.deleted');
    }
}
