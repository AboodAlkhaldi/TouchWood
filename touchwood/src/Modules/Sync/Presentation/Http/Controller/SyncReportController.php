<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Sync\Application\Command\DismissEntry\DismissEntry;
use Modules\Sync\Application\Command\DismissEntry\DismissEntryHandler;
use Modules\Sync\Application\Command\RetryEntry\RetryEntry;
use Modules\Sync\Application\Command\RetryEntry\RetryEntryHandler;
use Modules\Sync\Application\Command\SyncNow\SyncNow;
use Modules\Sync\Application\Command\SyncNow\SyncNowHandler;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Presentation\Http\Resource\SyncPages;
use Modules\Sync\Presentation\Http\Resource\SyncReportPage;

/**
 * **The Sync Report** (sync.md §4.1 Y1): the status line, Problems and Reads (`?store=&tab=&kind=&after=`),
 * and its changes - Sync Now, Retry Read and Dismiss Entry. The reads ask for View the Sync Report and the
 * handlers for Run Sync Reads in the store (§3); this checks nothing.
 */
final readonly class SyncReportController
{
    /** @var list<string> */
    public const array WORDS = ['sync::admin_report', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, SyncPages $pages): Response
    {
        return $this->page->render('Sync/Admin/Report', SyncForms::lazy(SyncReportPage::class, fn (): SyncReportPage => $pages->report(
            SyncForms::query($request, 'store'),
            SyncForms::query($request, 'tab'),
            SyncForms::query($request, 'kind'),
            SyncForms::query($request, 'after'),
        )), self::WORDS);
    }

    /** Sync Now: a whole read of the store, queued (Q26: one more follows a read running). */
    public function now(Request $request, SyncNowHandler $handler, SyncPages $pages): RedirectResponse
    {
        return SyncForms::act($request, function () use ($handler, $pages, $request): void {
            $handler->handle(new SyncNow($pages->storeId(SyncForms::text($request, 'store'), SyncPermissions::READ_RUN)));
        }, 'sync::admin_report.toast.read_queued');
    }

    /** Retry Read: the entry's code read again - a failed read's entry, the whole store. */
    public function retry(Request $request, int $entry, RetryEntryHandler $handler): RedirectResponse
    {
        return SyncForms::act($request, fn () => $handler->handle(new RetryEntry($entry)), 'sync::admin_report.toast.read_queued');
    }

    /** Dismiss Entry (Q28): it leaves the report, and comes back if a read finds it again. */
    public function dismiss(Request $request, int $entry, DismissEntryHandler $handler): RedirectResponse
    {
        return SyncForms::act($request, fn () => $handler->handle(new DismissEntry($entry)), 'sync::admin_report.toast.entry_dismissed');
    }
}
