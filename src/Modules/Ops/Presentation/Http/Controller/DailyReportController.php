<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
use Modules\Ops\Presentation\Http\Resource\DailyReportPages;

/*
| The daily reports (ops.md §1.11, §3, §4): make one - a store's, or every store's (no store) - today's
| or a past day's; list the kept ones; download one's PDF as it was made; and the reader's own emails of
| it. JSON for the screens agent's page; the PDF as a file. Every rule is the handlers'.
*/
final readonly class DailyReportController
{
    public function __construct(
        private DailyReportPages $pages,
    ) {}

    public function index(Request $request, ListDailyReportsHandler $list): JsonResponse
    {
        $after = $request->query('after');

        return new JsonResponse($this->pages->page($list->handle(new ListDailyReports(is_string($after) && $after !== '' && strlen($after) <= 64 ? $after : null)), app()->getLocale())->toArray());
    }

    public function store(Request $request, MakeDailyReportHandler $make): JsonResponse
    {
        $report = $make->handle(new MakeDailyReport(self::text($request, 'store'), self::text($request, 'day')));

        return new JsonResponse($this->pages->one($report, app()->getLocale())->toArray(), 201);
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

    public function setEmail(Request $request, SetDailyReportEmailHandler $set, ViewDailyReportEmailsHandler $view, DailyReportPeople $people): JsonResponse
    {
        $set->handle(new SetDailyReportEmail(self::text($request, 'store'), $request->boolean('on'), self::text($request, 'sendAt') ?? ''));

        return $this->emails($view, $people);
    }

    /** A text field as sent; empty or missing is none (for `store`: every store's report). */
    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' && strlen($value) <= 64 ? trim($value) : null;
    }
}
