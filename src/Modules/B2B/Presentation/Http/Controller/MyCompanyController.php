<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequest;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequestHandler;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\DiscardApplicationDraft\DiscardApplicationDraft;
use Modules\B2B\Application\Command\DiscardApplicationDraft\DiscardApplicationDraftHandler;
use Modules\B2B\Application\Command\RemoveApplicationAnswer\RemoveApplicationAnswer;
use Modules\B2B\Application\Command\RemoveApplicationAnswer\RemoveApplicationAnswerHandler;
use Modules\B2B\Application\Command\RemoveApplicationDocument\RemoveApplicationDocument;
use Modules\B2B\Application\Command\RemoveApplicationDocument\RemoveApplicationDocumentHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContact;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContactHandler;
use Modules\B2B\Application\Query\OpenMyApplicationFile\OpenMyApplicationFile;
use Modules\B2B\Application\Query\OpenMyApplicationFile\OpenMyApplicationFileHandler;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompany;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompanyHandler;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Presentation\Http\Request\CompanyAddressRequest;
use Modules\B2B\Presentation\Http\Request\CompanyDraftRequest;
use Modules\B2B\Presentation\Http\Resource\CompanyPages;
use Shared\Domain\Error\DomainError;

/**
 * F11 — the company's own page (b2b.md §4.5, amendment 14), and every change it makes: starting
 * the draft, the form saving itself a field at a time, its papers and answers, discarding and
 * sending it, and the company's address.
 *
 * No route carries an account: each handler reads who is asking from the session, and acts on the
 * account's one open application (§3.1). Every handler refuses an individual account
 * (NotACompanyAccount): the page then answers with the shop's 403, and a change with its message.
 *
 * **A refusal is shown where it belongs** (frontend.md §2.1): a value on its own field — so the
 * person sees it while still on that field (amendment 4) —, a paper beside its document type, an
 * answer beside its request, anything else at the top of the form.
 */
final readonly class MyCompanyController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'b2b::company', 'b2b::errors'];

    public function __construct(
        private Page $page,
        private CompanyPages $pages,
    ) {}

    public function show(ViewMyCompanyHandler $handler): Response
    {
        return $this->page->render('B2B/Storefront/Company/Company', $this->pages->page($handler->handle(new ViewMyCompany))->toArray(), self::WORDS);
    }

    /** The first draft, Apply again after a rejection, or Change company details (§4.5). */
    public function start(Request $request, StartApplicationDraftHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new StartApplicationDraft);
        } catch (DomainError $error) {
            return self::refused($request, $error);
        }

        return redirect()->route('storefront.company');
    }

    /** The form saving itself: usually one field, as the person leaves it (amendment 14(a)). */
    public function save(CompanyDraftRequest $request, SaveApplicationDraftHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new SaveApplicationDraft($request->fields()));
        } catch (InvalidCompanyAttribute $error) {
            // "Other"'s words are part of the type on the page: one field, one place for its refusal.
            return self::refused($request, $error, $error->attribute === 'company_type_other' ? 'company_type' : $error->attribute);
        } catch (CompanyTypeInactive $error) {
            return self::refused($request, $error, 'company_type');
        } catch (DomainError $error) {
            return self::refused($request, $error);
        }

        return back();
    }

    /** A paper under one document type: a second one replaces the first (§1.4). */
    public function attach(Request $request, string $type, AttachApplicationDocumentHandler $handler): RedirectResponse
    {
        $file = self::uploaded($request);

        if ($file === null) {
            return self::noFile($request, "documents.{$type}");
        }

        try {
            $handler->handle(new AttachApplicationDocument($type, (string) $file->getRealPath(), $file->getClientOriginalName()));
        } catch (DomainError $error) {
            return self::refused($request, $error, "documents.{$type}");
        }

        return back()->with('status', __('b2b::company.file_added'));
    }

    public function detach(Request $request, string $type, RemoveApplicationDocumentHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RemoveApplicationDocument($type));
        } catch (DomainError $error) {
            return self::refused($request, $error, "documents.{$type}");
        }

        return back();
    }

    /** What the last rejection asked for: a text, saved as the person leaves it, or a file. */
    public function answer(Request $request, string $answered, AnswerApplicationRequestHandler $handler): RedirectResponse
    {
        $file = self::uploaded($request);
        $text = $request->input('text');

        if ($file === null && ! is_string($text)) {
            return self::noFile($request, "answers.{$answered}");
        }

        try {
            $handler->handle($file === null
                ? new AnswerApplicationRequest($answered, text: (string) $text)
                : new AnswerApplicationRequest($answered, path: (string) $file->getRealPath(), originalFilename: $file->getClientOriginalName()));
        } catch (DomainError $error) {
            return self::refused($request, $error, "answers.{$answered}");
        }

        return back();
    }

    public function unanswer(Request $request, string $answered, RemoveApplicationAnswerHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RemoveApplicationAnswer($answered));
        } catch (DomainError $error) {
            return self::refused($request, $error, "answers.{$answered}");
        }

        return back();
    }

    public function discard(Request $request, DiscardApplicationDraftHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DiscardApplicationDraft);
        } catch (DomainError $error) {
            return self::refused($request, $error);
        }

        return redirect()->route('storefront.company')->with('status', __('b2b::company.discarded'));
    }

    public function send(Request $request, SubmitApplicationHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new SubmitApplication);
        } catch (DomainError $error) {
            return self::refused($request, $error);
        }

        return redirect()->route('storefront.company')->with('status', __('b2b::company.sent'));
    }

    /** The company's address, on its own and saved at once, without review (§1.1, §4.5). */
    public function address(CompanyAddressRequest $request, UpdateCompanyContactHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateCompanyContact($request->address()));
        } catch (DomainError $error) {
            // The form beside it is only there while no draft is open, so the field is the address.
            return self::refused($request, $error, 'address');
        }

        return back()->with('status', __('b2b::company.address_saved'));
    }

    /**
     * One of the account's own papers, through a link that lasts 30 minutes (§1.4). Anything else
     * answers "not found", the same whether or not it exists (ApplicationFileNotFound).
     */
    public function file(string $file, OpenMyApplicationFileHandler $handler): RedirectResponse
    {
        return redirect()->away($handler->handle(new OpenMyApplicationFile($file))->url);
    }

    /**
     * The refusal, in the person's language, where it belongs on the page — or, to a client asking
     * for JSON, the usual problem response (FormErrors).
     */
    private static function refused(Request $request, DomainError $error, string $where = 'form'): RedirectResponse
    {
        if ($where === 'form') {
            return FormErrors::back($request, $error);
        }

        if ($request->expectsJson()) {
            throw $error;
        }

        return back()->withErrors([$where => FormErrors::message($error)]);
    }

    private static function uploaded(Request $request): ?UploadedFile
    {
        $file = $request->file('file');

        return $file instanceof UploadedFile && $file->getRealPath() !== false ? $file : null;
    }

    /**
     * Nothing arrived, or nothing readable did — usually a file larger than the server accepts,
     * which never reaches the handler that would otherwise say so (as the media library says it).
     */
    private static function noFile(Request $request, string $where): RedirectResponse
    {
        return back()->withErrors([$where => __('b2b::company.no_file')]);
    }
}
