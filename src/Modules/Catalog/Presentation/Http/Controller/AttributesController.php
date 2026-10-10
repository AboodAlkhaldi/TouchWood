<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Catalog\Application\Command\ActivateAttribute\ActivateAttribute;
use Modules\Catalog\Application\Command\ActivateAttribute\ActivateAttributeHandler;
use Modules\Catalog\Application\Command\ActivateAttributeValue\ActivateAttributeValue;
use Modules\Catalog\Application\Command\ActivateAttributeValue\ActivateAttributeValueHandler;
use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttribute;
use Modules\Catalog\Application\Command\DeactivateAttribute\DeactivateAttributeHandler;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValue;
use Modules\Catalog\Application\Command\DeactivateAttributeValue\DeactivateAttributeValueHandler;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttribute;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttributeHandler;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValue;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValueHandler;
use Modules\Catalog\Application\Command\EditAttribute\EditAttribute;
use Modules\Catalog\Application\Command\EditAttribute\EditAttributeHandler;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValue;
use Modules\Catalog\Application\Command\EditAttributeValue\EditAttributeValueHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ListPages;
use Shared\Domain\Error\DomainError;

/**
 * The attributes screens (catalog.md §1.7, §4.4 S3): the list, one attribute with its values, and
 * every change to them — `catalog.attribute.manage` with All stores (§3).
 */
final readonly class AttributesController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_attributes', 'admin'];

    private const array FIELDS = ['name_ar', 'name_en', 'kind', 'unit_ar', 'unit_en', 'is_colour', 'position'];

    private const array VALUE_FIELDS = ['name_ar', 'name_en', 'swatch', 'position'];

    // What asks who is acting - the pages, the store choices - is taken by each action, never kept here:
    // a controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(ListPages $pages): Response
    {
        return $this->page->render('Catalog/Admin/Attributes/Index', $pages->attributes()->toArray(), self::WORDS);
    }

    public function show(string $attribute, ListPages $pages): Response
    {
        return $this->page->render('Catalog/Admin/Attributes/Show', $pages->attribute($attribute)->toArray(), self::WORDS);
    }

    public function add(CatalogFormRequest $request, AddAttributeHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AddAttribute(
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->text('kind'),
            $request->optionalText('unit_ar'),
            $request->optionalText('unit_en'),
            $request->boolean('is_colour'),
            $request->number('position'),
        )), 'catalog::admin_attributes.toast.added', self::FIELDS);
    }

    public function edit(CatalogFormRequest $request, string $attribute, EditAttributeHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new EditAttribute(
            $attribute,
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->text('kind'),
            $request->optionalText('unit_ar'),
            $request->optionalText('unit_en'),
            $request->boolean('is_colour'),
            $request->number('position'),
        )), 'catalog::admin_attributes.toast.edited', self::FIELDS);
    }

    public function activate(Request $request, string $attribute, ActivateAttributeHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ActivateAttribute($attribute)), 'catalog::admin_attributes.toast.activated');
    }

    public function deactivate(Request $request, string $attribute, DeactivateAttributeHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeactivateAttribute($attribute)), 'catalog::admin_attributes.toast.deactivated');
    }

    /** Its values go with it (§1.7); refused while a variation, a variant or a product uses it. */
    public function delete(Request $request, string $attribute, DeleteAttributeHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeleteAttribute($attribute));
        } catch (DomainError $error) {
            return CatalogRefusals::back($request, $error);
        }

        // Its page is gone with it: back to the list.
        return redirect()->route('catalog.admin.attributes')->with('status', __('catalog::admin_attributes.toast.deleted'));
    }

    public function addValue(CatalogFormRequest $request, string $attribute, AddAttributeValueHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AddAttributeValue(
            $attribute,
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->optionalText('swatch'),
            $request->number('position'),
        )), 'catalog::admin_attributes.toast.value_added', self::VALUE_FIELDS);
    }

    public function editValue(CatalogFormRequest $request, string $value, EditAttributeValueHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new EditAttributeValue(
            $value,
            $request->text('name_ar'),
            $request->text('name_en'),
            $request->optionalText('swatch'),
            $request->number('position'),
        )), 'catalog::admin_attributes.toast.value_edited', self::VALUE_FIELDS);
    }

    public function activateValue(Request $request, string $value, ActivateAttributeValueHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new ActivateAttributeValue($value)), 'catalog::admin_attributes.toast.value_activated');
    }

    public function deactivateValue(Request $request, string $value, DeactivateAttributeValueHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeactivateAttributeValue($value)), 'catalog::admin_attributes.toast.value_deactivated');
    }

    public function deleteValue(Request $request, string $value, DeleteAttributeValueHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteAttributeValue($value)), 'catalog::admin_attributes.toast.value_deleted');
    }
}
