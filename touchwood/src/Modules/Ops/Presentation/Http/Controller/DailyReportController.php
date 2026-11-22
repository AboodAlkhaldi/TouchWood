<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Response as InertiaResponse;
use Modules\Ops\Application\Command\MakeDailyReport\MakeDailyReport;
use Modules\Ops\Application\Command\MakeDailyReport\MakeDailyReportHandler;
use Modules\Ops\Application\Command\SetDailyReportEmail\SetDailyReportEmail;
use Modules\Ops\Application\Command\SetDailyReportEmail\SetDailyReportEmailHandler;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Application\Query\DownloadDailyReport\DownloadDailyReport;
use Modules\Ops\Application\Query\DownloadDailyReport\DownloadDailyReportHandler;
use Modules\Ops\Application\Query\ListDailyReports\ListDailyReports;
use Modules\Ops\Application\Query\ListDailyReports\ListDailyReportsHandler;
use Modules\Ops\Application\Query\ViewDailyReportEmails\ViewDailyReportEmails;
use Modules\Ops\Application\Query\ViewDailyReportEmails\ViewDailyReportEmailsHandler;
use Modules\Ops\Presentation\Http\Resource\DailyReportEmailData;
use Modules\Ops\Presentation\Http\Resource\DailyReportPages;
use Modules\Ops\Presentation\Http\Resource\DailyReportsScreenData;
use Modules\Ops\Presentation\Http\Resource\StoreChoiceData;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\Error\DomainError;

/*
| The daily reports (ops.md §1.11, §3, §4, §4.15 O9): make one - a store's, or every store's (no store) - today's
| or a past day's; list the kept ones; download one's PDF as it was made; and the reader's own emails of it. A plain
| visit gets the page and a screen's own read the JSON; a change the page sends goes back with its words (`Answers`),
| any other gets JSON. The PDF is a file. Every rule is the handlers'.
*/
final readonly class DailyReportController
{
    /** @var list<string> */
    private const array WORDS = ['ops::admin_daily_reports', 'ops::errors', 'admin'];

    public function __construct(
        private DailyReportPages $pages,
        private Page $page,
    ) {}

    public function index(Request $request, ListDailyReportsHandler $list, ViewDailyReportEmailsHandler $view, StoreChoices $choices, DailyReportPeople $people): InertiaResponse|JsonResponse
    {
        $after = $request->query('after');
        $page = $this->pages->page($list->handle(new ListDailyReports(is_string($after) && $after !== '' && strlen($after) <= 64 ? $after : null)), app()->getLocale());

        if ($request->expectsJson()) {
            return new JsonResponse($page->toArray());
        }

        $emails = $this->pages->emails($view->handle(new ViewDailyReportEmails), $people->allStoresZone(), app()->getLocale());
        $offered = $choices->forJobs(DailyReportPeople::PERMISSION);
        $reachable = [...$offered, ...self::switchedOff($emails, $offered, $people)];
        $superAdmin = $people->person()->isSuperAdmin;
        $zones = $superAdmin ? ['' => $people->allStoresZone()] : [];

        foreach ($reachable as $store) {
            $zones[$store->id] = $store->timezone;
        }

        return $this->page->render('Ops/Admin/DailyReports', (new DailyReportsScreenData(
            $page->reports,
            $page->next,
            $emails,
            StoreChoiceData::listOf($offered),
            StoreChoiceData::listOf($reachable),
            $superAdmin,
            $zones,
        ))->toArray(), self::WORDS);
    }

    public function store(Request $request, MakeDailyReportHandler $make): JsonResponse|RedirectResponse
    {
        $command = new MakeDailyReport(self::text($request, 'store'), self::text($request, 'day'));

        if (! Answers::fromPage($request)) {
            return new JsonResponse($this->pages->one($make->handle($command), app()->getLocale())->toArray(), 201);
        }

        try {
            $make->handle($command);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('ops::admin_daily_reports.make.toast'));
    }

    public function pdf(string $report, DownloadDailyReportHandler $download): Response
    {
        $file = $download->handle(new DownloadDailyReport($report));

        return new Response($file->pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function emails(ViewDailyReportEmailsHandler $view, DailyReportPeople $people): JsonResponse
    {
        return new JsonResponse(array_map(static fn ($email): array => $email->toArray(), $this->pages->emails($view->handle(new ViewDailyReportEmails), $people->allStoresZone(), app()->getLocale())));
    }

    public function setEmail(Request $request, SetDailyReportEmailHandler $set, ViewDailyReportEmailsHandler $view, DailyReportPeople $people): JsonResponse|RedirectResponse
    {
        $command = new SetDailyReportEmail(self::text($request, 'store'), $request->boolean('on'), self::text($request, 'sendAt') ?? '');

        if (! Answers::fromPage($request)) {
            $set->handle($command);

            return $this->emails($view, $people);
        }

        try {
            $set->handle($command);
        } catch (DomainError $error) {
            // The row says it: the page's toast is for what belongs to no row.
            return back()->withErrors(['email' => FormErrors::message($error)]);
        }

        return back();
    }

    /**
     * The stores of the reader's own emails that the page no longer offers them, because the store was switched off
     * while they still hold the permission there: the email keeps going, so they can still see and stop it (§4.15 O9).
     *
     * @param  list<DailyReportEmailData>  $emails
     * @param  list<StoreDto>  $offered
     * @return list<StoreDto>
     */
    private static function switchedOff(array $emails, array $offered, DailyReportPeople $people): array
    {
        $on = array_map(static fn (StoreDto $store): string => $store->id, $offered);
        $held = $people->stores();
        $held = $held === null ? null : array_map(static fn ($id): string => $id->value, $held);
        $off = [];

        foreach ($emails as $email) {
            if ($email->storeId !== null && ! in_array($email->storeId, $on, true) && ($held === null || in_array($email->storeId, $held, true))) {
                $off[] = $people->store($email->storeId);
            }
        }

        return $off;
    }

    /** A text field as sent; empty or missing is none (for `store`: every store's report). */
    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' && strlen($value) <= 64 ? trim($value) : null;
    }
}
