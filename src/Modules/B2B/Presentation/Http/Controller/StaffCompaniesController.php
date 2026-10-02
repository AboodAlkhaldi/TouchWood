<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompany;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompanyHandler;
use Modules\B2B\Application\Command\CorrectCompanyType\CorrectCompanyType;
use Modules\B2B\Application\Command\CorrectCompanyType\CorrectCompanyTypeHandler;
use Modules\B2B\Application\Command\DownloadCompanyDocument\DownloadCompanyDocument;
use Modules\B2B\Application\Command\DownloadCompanyDocument\DownloadCompanyDocumentHandler;
use Modules\B2B\Application\Command\ReinstateCompany\ReinstateCompany;
use Modules\B2B\Application\Command\ReinstateCompany\ReinstateCompanyHandler;
use Modules\B2B\Application\Command\RejectCompany\RejectCompany;
use Modules\B2B\Application\Command\RejectCompany\RejectCompanyHandler;
use Modules\B2B\Application\Command\SuspendCompany\SuspendCompany;
use Modules\B2B\Application\Command\SuspendCompany\SuspendCompanyHandler;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Presentation\Http\Request\StaffApproveRequest;
use Modules\B2B\Presentation\Http\Request\StaffCorrectTypeRequest;
use Modules\B2B\Presentation\Http\Request\StaffReasonRequest;
use Modules\B2B\Presentation\Http\Request\StaffRejectRequest;
use Modules\B2B\Presentation\Http\Resource\StaffCompanyPages;
use Shared\Domain\Error\DomainError;

/**
 * Companies, seen by staff (b2b.md §3.2, §4.6, amendment 19): the list, one company, and the
 * decisions taken on it.
 *
 * **A controller checks nothing itself** (frontend.md §4.1): each read model and each handler asks
 * for its own job in the company's home store, and a company of another store answers exactly as one
 * that does not exist (§7). A page someone may not open is the error page; a button someone may not
 * press is refused on the screen they pressed it on (App\Http\FormErrors).
 */
final readonly class StaffCompaniesController
{
    /** @var list<string> */
    private const array WORDS = ['b2b::admin_companies', 'admin'];

    public function __construct(
        private Page $page,
        private StaffCompanyPages $pages,
    ) {}

    public function index(Request $request): Response
    {
        $page = $request->integer('page', 1);

        return $this->page->render('B2B/Admin/Companies/Index', $this->pages->list(
            self::query($request, 'search'),
            self::query($request, 'status'),
            self::query($request, 'store'),
            max($page, 1),
        )->toArray(), self::WORDS);
    }

    public function show(string $company): Response
    {
        return $this->page->render('B2B/Admin/Companies/Show', $this->pages->view($company)->toArray(), self::WORDS);
    }

    /** An optional note, emailed with the approval (amendment 1). */
    public function approve(StaffApproveRequest $request, string $company, ApproveCompanyHandler $handler): RedirectResponse
    {
        return $this->act(
            $request,
            fn () => $handler->handle(new ApproveCompany($company, $request->optionalText('note'))),
            $company,
            'b2b::admin_companies.toast.approved',
            ['note' => 'note'],
        );
    }

    /** A reason, and what to fix and what to add (amendment 4). */
    public function reject(StaffRejectRequest $request, string $company, RejectCompanyHandler $handler): RedirectResponse
    {
        return $this->act(
            $request,
            fn () => $handler->handle(new RejectCompany(
                $company,
                $request->text('reason'),
                $request->texts('flags'),
                $request->texts('documents'),
                $request->requested(),
            )),
            $company,
            'b2b::admin_companies.toast.rejected',
            ['reason' => 'reason', 'flags' => 'flags', 'requests' => 'requests', 'label' => 'requests', 'position' => 'requests'],
        );
    }

    public function suspend(StaffReasonRequest $request, string $company, SuspendCompanyHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new SuspendCompany($company, $request->text('reason'))), $company, 'b2b::admin_companies.toast.suspended', ['reason' => 'reason']);
    }

    public function reinstate(StaffReasonRequest $request, string $company, ReinstateCompanyHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new ReinstateCompany($company, $request->text('reason'))), $company, 'b2b::admin_companies.toast.reinstated', ['reason' => 'reason']);
    }

    /**
     * A listed type of the home store, or "Other" in words; a deactivated type only once the person
     * confirmed it becomes active again (amendment 8(b)).
     */
    public function correctType(StaffCorrectTypeRequest $request, string $company, CorrectCompanyTypeHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new CorrectCompanyType(
                $company,
                $request->optionalText('type_id'),
                $request->optionalText('other'),
                $request->boolean('confirm_reactivation'),
            ));
        } catch (CompanyTypeInactive $error) {
            return StaffRefusals::on($request, $error, 'type');
        } catch (DomainError $error) {
            return StaffRefusals::back($request, $error, ['company_type' => 'type', 'company_type_other' => 'other']);
        }

        return $this->done($company, 'b2b::admin_companies.toast.type_corrected');
    }

    /**
     * A paper or a file answer of a sent application, through a link that lasts 30 minutes; the
     * opening is audited before the link is handed back (amendment 10(f)). Anything else answers
     * "not found", the same whether or not it exists.
     */
    public function file(string $company, string $file, DownloadCompanyDocumentHandler $handler): RedirectResponse
    {
        return redirect()->away($handler->handle(new DownloadCompanyDocument($company, $file))->url);
    }

    /**
     * @param  callable(): void  $act
     * @param  array<string, string>  $fields  the domain's attribute => the screen's field
     */
    private function act(Request $request, callable $act, string $company, string $message, array $fields): RedirectResponse
    {
        try {
            $act();
        } catch (DomainError $error) {
            return StaffRefusals::back($request, $error, $fields);
        }

        return $this->done($company, $message);
    }

    private function done(string $company, string $message): RedirectResponse
    {
        return redirect()->route('b2b.admin.companies.show', ['company' => $company])->with('status', __($message));
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
