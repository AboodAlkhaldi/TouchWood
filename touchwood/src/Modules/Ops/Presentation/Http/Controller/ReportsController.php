<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\DownloadReport\DownloadReport;
use Modules\Ops\Application\Query\DownloadReport\DownloadReportHandler;
use Modules\Ops\Application\Query\ViewReport\ViewReport;
use Modules\Ops\Application\Query\ViewReport\ViewReportHandler;
use Modules\Ops\Application\Reports\ReportColumn;
use Modules\Ops\Application\Reports\ReportKind;
use Modules\Ops\Application\Reports\ReportMoney;
use Modules\Ops\Application\Reports\ReportReaders;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Presentation\Http\Resource\ReportAskedData;
use Modules\Ops\Presentation\Http\Resource\ReportChoiceData;
use Modules\Ops\Presentation\Http\Resource\ReportChoices;
use Modules\Ops\Presentation\Http\Resource\ReportColumnData;
use Modules\Ops\Presentation\Http\Resource\ReportPreviewData;
use Modules\Ops\Presentation\Http\Resource\ReportSheetData;
use Modules\Ops\Presentation\Http\Resource\ReportsPageData;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| The reports in the panel (ops.md §1.10, §3, §4, §4.15 O8): a report's page, its preview as JSON, and its Excel file.
| The choices are the query's: `store` (a store's id, or `all`; the page's own address names it by its code, owner
| 2026-11-05), `from` and `to` (days, `Y-m-d`) and, for Revenue, `by` (`day` or `month`). Who may read which is Ops'
| answer, never the routing's.
|
| The same address answers a page to a plain visit and the preview's JSON to whoever asks for it (§4.15, Q1). The
| page reads the preview itself once the address names choices - Show Report puts them there (Q18) - so a reload or
| a shared link shows the report again; a refusal of those choices comes back in words, in place (§4.15 O8).
*/
final readonly class ReportsController
{
    /** The longest a download may take to write. */
    public const int DOWNLOAD_SECONDS = 600;

    /** @var list<string> */
    private const array WORDS = ['ops::admin_reports', 'ops::errors', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    /**
     * `/admin/reports`: the first kind this reader may open; 403 for none (§4.15 O8). A redirect, so a signed-out
     * visit to it is not remembered for after sign-in (StaffReturnAddress); one to a kind's own address is.
     */
    public function index(ReportChoices $choices): RedirectResponse
    {
        $first = $choices->offered()['reports'][0] ?? throw new Unauthorized(OpsPermissions::REPORT_VIEW);

        return redirect()->route('ops.admin.reports.show', ['report' => $first->kind->value]);
    }

    public function show(Request $request, string $report, ViewReportHandler $view, ReportChoices $choices): InertiaResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse($this->preview(self::choices($request), $report, $view)->toArray());
        }

        $kind = ReportKind::tryFrom($report) ?? throw new InvalidOpsInput('report', 'not a report');
        $offered = $choices->offered();
        // A kind not offered is a page that is not theirs: the 403 page, as for any address followed (FormErrors).
        $open = array_values(array_filter($offered['reports'], static fn (ReportChoiceData $choice): bool => $choice->kind === $kind))[0]
            ?? throw new Unauthorized(OpsPermissions::REPORT_VIEW);
        $asked = self::choices($request);
        $preview = null;
        $refusal = null;

        if ($asked !== []) {
            try {
                $preview = $this->preview([...$asked, 'store' => self::storeOf($asked['store'] ?? '', $open)], $report, $view);
            } catch (DomainError $error) {
                $refusal = FormErrors::message($error);
            }
        }

        $shown = $asked === [] ? null : new ReportAskedData(strtolower($asked['store'] ?? ''), $asked['from'] ?? '', $asked['to'] ?? '', $asked['by'] ?? 'day');

        return $this->page->render('Ops/Admin/Reports', (new ReportsPageData(
            $kind,
            $offered['reports'],
            $offered['zones'],
            $shown ?? self::opening($open, $offered['zones']),
            $shown,
            $preview,
            $refusal,
        ))->toArray(), self::WORDS);
    }

    public function download(Request $request, string $report, DownloadReportHandler $download): BinaryFileResponse
    {
        // Five years of a busy store's days take longer than a page's 30 seconds to write; ten minutes at most.
        set_time_limit(self::DOWNLOAD_SECONDS);
        $choices = self::choices($request);
        $file = $download->handle(new DownloadReport($report, $choices['store'] ?? '', $choices['from'] ?? null, $choices['to'] ?? null, $choices['by'] ?? null));

        return response()->download($file->path, $file->filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * The preview the choices ask for (§4.6), the store named by its id or `all`; its `downloadUrl` names it so too.
     *
     * @param  array<'store'|'from'|'to'|'by', string>  $choices
     *
     * @throws DomainError
     */
    private function preview(array $choices, string $report, ViewReportHandler $view): ReportPreviewData
    {
        $preview = $view->handle(new ViewReport($report, $choices['store'] ?? '', $choices['from'] ?? null, $choices['to'] ?? null, $choices['by'] ?? null));
        $sheets = [];
        foreach ($preview->report->sheets as $index => $sheet) {
            $sheets[] = new ReportSheetData(
                $sheet->name,
                array_map(static fn (ReportColumn $column): ReportColumnData => new ReportColumnData($column->key, $column->label, $column->type->value), $sheet->columns),
                $sheet->notes,
                array_map(self::plain(...), $preview->rows[$index] ?? []),
                array_map(self::plain(...), $preview->totals[$index] ?? []),
                $preview->more[$index] ?? false,
            );
        }

        return new ReportPreviewData(
            $preview->report->kind->value,
            $preview->report->locale,
            $preview->report->rightToLeft(),
            $preview->report->title,
            $preview->report->subtitle,
            $sheets,
            $preview->report->filename,
            route('ops.admin.reports.download', ['report' => $report, ...$choices], false),
        );
    }

    /**
     * The choices a form opens on before any is shown (§4.15 O8, Q19): the first store on offer that is on - a Super
     * Admin's too - else the first offered, else every store's; and from the 1st of this month to today, in that
     * store's time.
     *
     * @param  array<string, string>  $zones
     */
    private static function opening(ReportChoiceData $open, array $zones): ReportAskedData
    {
        $on = array_values(array_filter($open->stores, static fn ($store): bool => $store->isOn));
        $store = ($on[0] ?? $open->stores[0] ?? null)->code ?? ReportReaders::ALL;
        $today = CarbonImmutable::now($zones[$store] ?? 'UTC');

        return new ReportAskedData($store, $today->startOfMonth()->format('Y-m-d'), $today->format('Y-m-d'), 'day');
    }

    /**
     * A row as the preview answers it: an amount as its decimal string.
     *
     * @param  list<string|int|ReportMoney|null>  $row
     * @return list<string|int|null>
     */
    private static function plain(array $row): array
    {
        return array_map(static fn (string|int|ReportMoney|null $value): string|int|null => $value instanceof ReportMoney ? $value->decimal() : $value, $row);
    }

    /**
     * The page's address names a store by its code, as Ops' other pages do, or `all` (owner, 2026-11-05); the reports
     * read it by its id (§4.6), which the JSON and `downloadUrl` keep. A code that is not among this kind's stores on
     * offer - unknown, or not this reader's - is refused as a store not offered is (403, in words on the page).
     *
     * @throws Unauthorized
     */
    private static function storeOf(string $code, ReportChoiceData $open): string
    {
        $code = strtolower($code);

        if ($code === '' || $code === ReportReaders::ALL) {
            return $code;
        }

        foreach ($open->stores as $store) {
            if ($store->code === $code) {
                return $store->id;
            }
        }

        throw new Unauthorized(OpsPermissions::REPORT_VIEW);
    }

    /**
     * The choices the request makes, each a text or left out.
     *
     * @return array<'store'|'from'|'to'|'by', string>
     */
    private static function choices(Request $request): array
    {
        $choices = [];
        foreach (['store', 'from', 'to', 'by'] as $key) {
            $value = Inputs::text($request, $key);
            if ($value !== null) {
                $choices[$key] = $value;
            }
        }

        return $choices;
    }
}
