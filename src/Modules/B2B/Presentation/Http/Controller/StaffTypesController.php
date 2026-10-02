<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\B2B\Application\Command\ActivateCompanyType\ActivateCompanyType;
use Modules\B2B\Application\Command\ActivateCompanyType\ActivateCompanyTypeHandler;
use Modules\B2B\Application\Command\ActivateDocumentType\ActivateDocumentType;
use Modules\B2B\Application\Command\ActivateDocumentType\ActivateDocumentTypeHandler;
use Modules\B2B\Application\Command\AddCompanyType\AddCompanyType;
use Modules\B2B\Application\Command\AddCompanyType\AddCompanyTypeHandler;
use Modules\B2B\Application\Command\AddDocumentType\AddDocumentType;
use Modules\B2B\Application\Command\AddDocumentType\AddDocumentTypeHandler;
use Modules\B2B\Application\Command\DeactivateCompanyType\DeactivateCompanyType;
use Modules\B2B\Application\Command\DeactivateCompanyType\DeactivateCompanyTypeHandler;
use Modules\B2B\Application\Command\DeactivateDocumentType\DeactivateDocumentType;
use Modules\B2B\Application\Command\DeactivateDocumentType\DeactivateDocumentTypeHandler;
use Modules\B2B\Application\Command\MarkTypeListsReviewed\MarkTypeListsReviewed;
use Modules\B2B\Application\Command\MarkTypeListsReviewed\MarkTypeListsReviewedHandler;
use Modules\B2B\Application\Command\MoveCompanyType\MoveCompanyType;
use Modules\B2B\Application\Command\MoveCompanyType\MoveCompanyTypeHandler;
use Modules\B2B\Application\Command\MoveDocumentType\MoveDocumentType;
use Modules\B2B\Application\Command\MoveDocumentType\MoveDocumentTypeHandler;
use Modules\B2B\Application\Command\RenameCompanyType\RenameCompanyType;
use Modules\B2B\Application\Command\RenameCompanyType\RenameCompanyTypeHandler;
use Modules\B2B\Application\Command\RenameDocumentType\RenameDocumentType;
use Modules\B2B\Application\Command\RenameDocumentType\RenameDocumentTypeHandler;
use Modules\B2B\Application\Command\RequireDocumentType\RequireDocumentType;
use Modules\B2B\Application\Command\RequireDocumentType\RequireDocumentTypeHandler;
use Modules\B2B\Application\Command\TransferCompanyType\TransferCompanyType;
use Modules\B2B\Application\Command\TransferCompanyType\TransferCompanyTypeHandler;
use Modules\B2B\Application\Query\ViewTypeLists\ViewTypeLists;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Presentation\Http\Request\StaffDeactivateTypeRequest;
use Modules\B2B\Presentation\Http\Request\StaffTransferTypeRequest;
use Modules\B2B\Presentation\Http\Request\StaffTypeRequest;
use Modules\B2B\Presentation\Http\Resource\StaffTypePages;
use Shared\Domain\Error\DomainError;

/**
 * The types page (b2b.md §1.3, §3.2, §4.6, amendment 19): one store's company types and document
 * types, and every change staff make to them.
 *
 * **The store is the panel's**, the one in the header (frontend.md §2.2): a type is changed in its own
 * store, which its handler reads from the type; adding one and "Reviewed" name the header's store,
 * never one from the request. Each handler asks for its own job in that store; this checks nothing.
 */
final readonly class StaffTypesController
{
    /** @var list<string> */
    private const array WORDS = ['b2b::admin_types', 'admin'];

    /** The domain's names for a type's fields, as the add / rename / move forms name them. */
    private const array TYPE_FIELDS = ['name_ar' => 'name_ar', 'name_en' => 'name_en', 'position' => 'position'];

    public function __construct(
        private Page $page,
        private StaffTypePages $pages,
    ) {}

    public function companyTypes(): Response
    {
        return $this->page->render('B2B/Admin/Types/Index', $this->pages->page(ViewTypeLists::COMPANY)->toArray(), self::WORDS);
    }

    public function documentTypes(): Response
    {
        return $this->page->render('B2B/Admin/Types/Index', $this->pages->page(ViewTypeLists::DOCUMENT)->toArray(), self::WORDS);
    }

    public function addCompanyType(StaffTypeRequest $request, AddCompanyTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new AddCompanyType(
            $this->pages->storeId(), $request->text('name_ar'), $request->text('name_en'), $request->number('position'),
        )), 'b2b::admin_types.toast.company.added', self::TYPE_FIELDS);
    }

    public function renameCompanyType(StaffTypeRequest $request, string $type, RenameCompanyTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new RenameCompanyType($type, $request->text('name_ar'), $request->text('name_en'))), 'b2b::admin_types.toast.company.renamed', self::TYPE_FIELDS);
    }

    public function moveCompanyType(StaffTypeRequest $request, string $type, MoveCompanyTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new MoveCompanyType($type, $request->number('position'))), 'b2b::admin_types.toast.company.moved', self::TYPE_FIELDS);
    }

    /**
     * How it shows, and what happens to the companies holding it: left with it, moved to another
     * active type, or moved to a new type made in the same step (amendment 11(b)).
     */
    public function deactivateCompanyType(StaffDeactivateTypeRequest $request, string $type, DeactivateCompanyTypeHandler $handler): RedirectResponse
    {
        $holders = $request->holders();
        $new = $holders === StaffDeactivateTypeRequest::NEW;

        return $this->act($request, fn () => $handler->handle(new DeactivateCompanyType(
            $type,
            InactiveTypeDisplay::from($request->text('shown')),
            replacementTypeId: $holders === StaffDeactivateTypeRequest::REPLACE ? $request->text('replacement') : null,
            newTypeNameAr: $new ? $request->text('new_name_ar') : null,
            newTypeNameEn: $new ? $request->text('new_name_en') : null,
            // The old type's position unless one is given (amendment 11(b)).
            newTypePosition: $new && $request->optionalText('new_position') !== null ? $request->number('new_position') : null,
        )), 'b2b::admin_types.toast.company.deactivated', [
            'replacement' => 'replacement',
            'name_ar' => 'new_name_ar',
            'name_en' => 'new_name_en',
            'position' => 'new_position',
        ]);
    }

    public function activateCompanyType(Request $request, string $type, ActivateCompanyTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new ActivateCompanyType($type)), 'b2b::admin_types.toast.company.activated');
    }

    /** Every company of one active type to another, both staying offered (amendment 11(c)). */
    public function transferCompanyType(StaffTransferTypeRequest $request, string $type, TransferCompanyTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new TransferCompanyType($type, $request->text('target'))), 'b2b::admin_types.toast.company.transferred', ['target' => 'target']);
    }

    public function addDocumentType(StaffTypeRequest $request, AddDocumentTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new AddDocumentType(
            $this->pages->storeId(), $request->text('name_ar'), $request->text('name_en'), $request->number('position'), $request->boolean('required'),
        )), 'b2b::admin_types.toast.document.added', self::TYPE_FIELDS);
    }

    public function renameDocumentType(StaffTypeRequest $request, string $type, RenameDocumentTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new RenameDocumentType($type, $request->text('name_ar'), $request->text('name_en'))), 'b2b::admin_types.toast.document.renamed', self::TYPE_FIELDS);
    }

    public function moveDocumentType(StaffTypeRequest $request, string $type, MoveDocumentTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new MoveDocumentType($type, $request->number('position'))), 'b2b::admin_types.toast.document.moved', self::TYPE_FIELDS);
    }

    public function requireDocumentType(StaffTypeRequest $request, string $type, RequireDocumentTypeHandler $handler): RedirectResponse
    {
        $required = $request->boolean('required');

        return $this->act(
            $request,
            fn () => $handler->handle(new RequireDocumentType($type, $required)),
            $required ? 'b2b::admin_types.toast.document.required' : 'b2b::admin_types.toast.document.optional',
        );
    }

    public function deactivateDocumentType(StaffDeactivateTypeRequest $request, string $type, DeactivateDocumentTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new DeactivateDocumentType($type, InactiveTypeDisplay::from($request->text('shown')))), 'b2b::admin_types.toast.document.deactivated');
    }

    public function activateDocumentType(Request $request, string $type, ActivateDocumentTypeHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new ActivateDocumentType($type)), 'b2b::admin_types.toast.document.activated');
    }

    /** Nothing to change: the "copied" notice goes (amendment 10(d)). */
    public function markReviewed(Request $request, MarkTypeListsReviewedHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new MarkTypeListsReviewed($this->pages->storeId())), 'b2b::admin_types.toast.reviewed');
    }

    /**
     * @param  callable(): mixed  $act
     * @param  array<string, string>  $fields  the domain's attribute => the screen's field
     */
    private function act(Request $request, callable $act, string $message, array $fields = []): RedirectResponse
    {
        try {
            $act();
        } catch (DomainError $error) {
            return StaffRefusals::back($request, $error, $fields);
        }

        return back()->with('status', __($message));
    }
}
