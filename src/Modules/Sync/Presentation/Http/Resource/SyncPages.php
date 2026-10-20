<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sync\Application\Query\ListSyncReads\ListSyncReads;
use Modules\Sync\Application\Query\ListSyncReads\ListSyncReadsHandler;
use Modules\Sync\Application\Query\ListSyncReads\ReadView;
use Modules\Sync\Application\Query\SyncActions\SyncActionsForReader;
use Modules\Sync\Application\Query\SyncStatus\SyncStatus;
use Modules\Sync\Application\Query\SyncStatus\SyncStatusHandler;
use Modules\Sync\Application\Query\SyncStatus\SyncStatusView;
use Modules\Sync\Application\Query\ViewConnection\ViewConnection;
use Modules\Sync\Application\Query\ViewConnection\ViewConnectionHandler;
use Modules\Sync\Application\Query\ViewSyncRead\ReadLineView;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncRead;
use Modules\Sync\Application\Query\ViewSyncRead\ViewSyncReadHandler;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReport;
use Modules\Sync\Application\Query\ViewSyncReport\ViewSyncReportHandler;
use Modules\Sync\Application\Report\FailureView;
use Modules\Sync\Application\Report\OfferRefView;
use Modules\Sync\Application\Report\ReportedView;
use Modules\Sync\Application\Report\ReportEntryView;
use Modules\Sync\Application\SyncPermissions;
use Shared\Application\Unauthorized;

/**
 * Sync's screens' data, in the shape the pages want (sync.md §4.1, stage 5's screens step 6).
 *
 * It decides nothing: what the reader may read and do is Sync's answer - the reads, and
 * `SyncActionsForReader` - and which store a page shows is Platform's `StoreChoices`, as on every store
 * screen (frontend.md §2.2): the stores where the reader views the report (the Provider page: every store,
 * a Super Admin's), an off one only to whoever may switch stores; the first that is on when none is asked;
 * any other refused (403). What happens here is shaping: the store's code into its id, the tab a store not
 * wired may show (Q35), the reads' answers into the pages' data.
 */
final readonly class SyncPages
{
    public const string PROBLEMS = 'problems';

    public const string READS = 'reads';

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private SyncActionsForReader $actions,
        private SyncStatusHandler $status,
        private ViewSyncReportHandler $report,
        private ListSyncReadsHandler $reads,
        private ViewSyncReadHandler $read,
        private ViewConnectionHandler $connection,
    ) {}

    /**
     * The store a screen shows, by the code in its address - the first that is on when none is asked.
     *
     * @throws Unauthorized when it is not one of the reader's, or the reader has none
     */
    public function store(?string $code, string $job = SyncPermissions::REPORT_VIEW): StoreDto
    {
        return $this->choices->chosen($code, $job) ?? throw new Unauthorized($job);
    }

    /**
     * The store an action names - always named: a form that names none is refused, never sent to the
     * first store. The handler then asks for its own job there.
     *
     * @throws Unauthorized
     */
    public function storeId(?string $code, string $job): string
    {
        if ($code === null || trim($code) === '') {
            throw new Unauthorized($job);
        }

        return $this->store($code, $job)->id;
    }

    /**
     * **The Sync Report** (Y1): the status line, and the tab asked for - Problems by default; a store not
     * wired has no Problems tab and shows its Reads (Q35).
     */
    public function report(?string $code, ?string $tab, ?string $kind, ?string $after): SyncReportPage
    {
        $store = $this->store($code);
        $status = $this->status->handle(new SyncStatus($store->id));
        $shown = $status->state === SyncStatusView::NOT_WIRED || $tab === self::READS ? self::READS : self::PROBLEMS;
        $problems = $reads = null;

        if ($shown === self::PROBLEMS) {
            $page = $this->report->handle(new ViewSyncReport($store->id, $kind, $after));
            $problems = new ProblemsData(
                array_map(fn (ReportEntryView $entry): ReportEntryData => new ReportEntryData($entry->id, $this->reported($entry->about), $entry->firstSeenAt, $entry->lastSeenAt), $page->entries),
                $page->next,
                $page->total,
                $page->allOpen,
                array_map(static fn (string $each, int $count): KindCountData => new KindCountData($each, $count), array_keys($page->byKind), array_values($page->byKind)),
            );
        } else {
            $page = $this->reads->handle(new ListSyncReads($store->id, $after));
            $reads = new ReadsData(array_map($this->readRow(...), $page->reads), $page->next, $page->total, $page->openEntries);
        }

        return new SyncReportPage(
            $this->frame($store, SyncPermissions::REPORT_VIEW),
            $store->timezone,
            new SyncStatusData(
                $status->provider,
                $status->providerName,
                $status->state,
                self::failure($status->failure),
                $status->lastReadAt,
                $status->lastFullReadAt,
                $status->running === null ? null : new ReadMomentData($status->running->kind, $status->running->at),
                $status->waiting === null ? null : new ReadMomentData($status->waiting->kind, $status->waiting->at),
            ),
            $shown,
            $shown === self::PROBLEMS && $kind !== null ? strtoupper(trim($kind)) : null,
            $problems,
            $reads,
        );
    }

    /** **A read** (Y2): one section, one page of it. */
    public function read(string $readId, ?string $section, int $page): SyncReadPage
    {
        $view = $this->read->handle(new ViewSyncRead($readId, $section ?? ViewSyncRead::PRICES, $page));
        $actions = $this->actions->forStore($view->storeId);

        return new SyncReadPage(
            $view->storeCode,
            $view->storeTimezone,
            $this->readRow($view->read),
            new SectionTotalsData($view->sections['prices'], $view->sections['stock'], $view->sections['reported'], $view->sections['codes']),
            $view->section,
            $view->page,
            $view->perPage,
            $view->more,
            array_map(static fn (ReadLineView $line): ReadLineData => new ReadLineData(...get_object_vars($line)), $view->lines),
            array_map($this->reported(...), $view->reported),
            $view->codes,
            $actions->mayViewProducts ?? false,
            $actions->mayViewOffers ?? false,
        );
    }

    /** **The Provider page** (Y3), a Super Admin's: any store, the off ones too. */
    public function provider(?string $code): ProviderPage
    {
        $store = $this->store($code, SyncPermissions::CONNECTION_MANAGE);
        $view = $this->connection->handle(new ViewConnection($store->id));

        return new ProviderPage(
            $this->frame($store, SyncPermissions::CONNECTION_MANAGE),
            $store->timezone,
            new ConnectionData(
                $view->storeId,
                $view->providers,
                $view->provider,
                $view->providerName,
                $view->providerReady,
                $view->address,
                $view->settings,
                $view->fields,
                $view->keySet,
                $view->changedAt,
                $view->state,
                self::failure($view->failure),
                $view->lastReadAt,
                $view->lastFullReadAt,
                $view->noticeAddress,
                $view->noticeMostCodes,
                $view->stopsWhenUnwired,
            ),
        );
    }

    /**
     * @throws Unauthorized
     */
    private function frame(StoreDto $store, string $job): SyncFrameData
    {
        $actions = $this->actions->forStore($store->id) ?? throw new Unauthorized($job);
        $reasons = $this->actions->reasons();
        $locale = $this->app->getLocale() === 'en' ? 'en' : 'ar';

        return new SyncFrameData(
            $store->id,
            $store->code,
            $store->name->in($locale),
            array_map(static fn (StoreDto $each): SyncStoreOptionData => new SyncStoreOptionData($each->code, $each->name->in($locale), $each->isActive), $this->choices->forJobs($job)),
            $actions->off,
            $actions->wired,
            $actions->mayView,
            $actions->mayRun,
            $actions->mayWire,
            $actions->mayViewProducts,
            $actions->mayViewOffers,
            $actions->nowWaiting,
            $actions->syncNowRefusal,
            new SyncReasonsData($reasons->sizeGone, $reasons->readFailedStays),
        );
    }

    private function readRow(ReadView $read): ReadRowData
    {
        return new ReadRowData(...[...get_object_vars($read), 'failure' => self::failure($read->failure)]);
    }

    private function reported(ReportedView $about): ReportedData
    {
        return new ReportedData(...[
            ...get_object_vars($about),
            'offer' => self::offer($about->offer),
            'discount' => self::offer($about->discount),
            'failure' => self::failure($about->failure),
        ]);
    }

    private static function failure(?FailureView $failure): ?FailureData
    {
        return $failure === null ? null : new FailureData($failure->code, $failure->text, $failure->providerSaid);
    }

    private static function offer(?OfferRefView $offer): ?OfferRefData
    {
        return $offer === null ? null : new OfferRefData($offer->id, $offer->exists, $offer->description);
    }
}
