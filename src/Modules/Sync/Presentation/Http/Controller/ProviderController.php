<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnection;
use Modules\Sync\Application\Command\ChangeConnection\ChangeConnectionHandler;
use Modules\Sync\Application\Command\UnwireStore\UnwireStore;
use Modules\Sync\Application\Command\UnwireStore\UnwireStoreHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Presentation\Http\Resource\ProviderPage;
use Modules\Sync\Presentation\Http\Resource\SyncPages;

/**
 * **The Provider page** (sync.md §4.1 Y3, `?store=`), a Super Admin's: the store's connection, and its
 * changes - Test and Wire Store, Test and Save Connection (an empty key or secret keeps the current one),
 * Unwire Store. The read and the handlers ask for `sync.connection.manage` (§3); this checks nothing. The
 * key and the secret are never kept in the form when it is refused.
 */
final readonly class ProviderController
{
    /** @var list<string> */
    public const array WORDS = ['sync::admin_provider', 'sync::admin_report', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, SyncPages $pages): Response
    {
        return $this->page->render('Sync/Admin/Provider', SyncForms::lazy(ProviderPage::class, fn (): ProviderPage => $pages->provider(
            SyncForms::query($request, 'store'),
        )), self::WORDS);
    }

    public function wire(Request $request, WireStoreHandler $handler, SyncPages $pages): RedirectResponse
    {
        return SyncForms::act($request, function () use ($handler, $pages, $request): void {
            $handler->handle(new WireStore(
                $pages->storeId(SyncForms::text($request, 'store'), SyncPermissions::CONNECTION_MANAGE),
                (string) SyncForms::text($request, 'provider'),
                (string) SyncForms::text($request, 'address'),
                is_string($request->input('key')) ? $request->input('key') : '',
                is_string($request->input('secret')) ? $request->input('secret') : '',
                SyncForms::settings($request),
            ));
        }, 'sync::admin_provider.toast.store_wired');
    }

    public function change(Request $request, ChangeConnectionHandler $handler, SyncPages $pages): RedirectResponse
    {
        return SyncForms::act($request, function () use ($handler, $pages, $request): void {
            $handler->handle(new ChangeConnection(
                $pages->storeId(SyncForms::text($request, 'store'), SyncPermissions::CONNECTION_MANAGE),
                (string) SyncForms::text($request, 'provider'),
                (string) SyncForms::text($request, 'address'),
                // Left empty: the current one is kept.
                is_string($request->input('key')) && $request->input('key') !== '' ? $request->input('key') : null,
                is_string($request->input('secret')) && $request->input('secret') !== '' ? $request->input('secret') : null,
                SyncForms::settings($request),
            ));
        }, 'sync::admin_provider.toast.connection_changed');
    }

    public function unwire(Request $request, UnwireStoreHandler $handler, SyncPages $pages): RedirectResponse
    {
        return SyncForms::act($request, function () use ($handler, $pages, $request): void {
            $handler->handle(new UnwireStore($pages->storeId(SyncForms::text($request, 'store'), SyncPermissions::CONNECTION_MANAGE)));
        }, 'sync::admin_provider.toast.store_unwired');
    }
}
