<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStock;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStockHandler;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoon;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoonHandler;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThreshold;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThresholdHandler;
use Modules\Inventory\Application\Command\SetStock\SetStock;
use Modules\Inventory\Application\Command\SetStock\SetStockHandler;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependent;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependentHandler;
use Modules\Inventory\Application\Query\ListStock\ListStock;
use Modules\Inventory\Presentation\Http\Resource\HistoryData;
use Modules\Inventory\Presentation\Http\Resource\InventoryPages;
use Modules\Inventory\Presentation\Http\Resource\SizeChoicesData;
use Modules\Inventory\Presentation\Http\Resource\SizeStockData;
use Modules\Inventory\Presentation\Http\Resource\StockPage;

/**
 * **Stock** (inventory.md §4.3 I1): the store's sizes and their figures, the size drawer (`?size=`, Q3) with
 * its history, Add Size's picker - and every change: the counts typed in the rows (one `SetStock`, only the
 * rows typed with a new number, Q19), Add or Remove Stock, Set Threshold (one size or the selection), Show /
 * Hide Last Pieces, Counts on Stock. The changes serve Low Stock and the product page too. Each handler asks
 * for Manage Stock in the store (§3); this checks nothing.
 */
final readonly class StockController
{
    /** @var list<string> */
    public const array WORDS = ['inventory::admin_stock', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, InventoryPages $pages): Response
    {
        $filter = InventoryForms::word($request, 'filter') ?? ListStock::ALL;
        $store = InventoryForms::query($request, 'store');
        $size = InventoryForms::query($request, 'size');

        return $this->page->render('Inventory/Admin/Stock', [
            ...InventoryForms::lazy(StockPage::class, fn (): StockPage => $pages->stock($store, InventoryForms::query($request, 'q'), $filter, InventoryForms::query($request, 'after'), $size)),
            // The drawer's own reads, only while the address names a size (Q3): its figures and its history's first page.
            'drawer' => static fn (): ?array => $size === null ? null : $pages->sizeStock($pages->store($store)->id, $size)->toArray(),
            'history' => static fn (): ?array => $size === null ? null : $pages->history($pages->store($store)->id, $size, null)->toArray(),
        ], self::WORDS);
    }

    /** A size's figures, for its dialogs where the page has no row of it. */
    public function size(Request $request, InventoryPages $pages): SizeStockData
    {
        return $pages->sizeStock($pages->storeId(InventoryForms::query($request, 'store')), (string) InventoryForms::query($request, 'size'));
    }

    /** The history's next page (Show More in the drawer). */
    public function history(Request $request, InventoryPages $pages): HistoryData
    {
        return $pages->history($pages->storeId(InventoryForms::query($request, 'store')), (string) InventoryForms::query($request, 'size'), InventoryForms::query($request, 'after'));
    }

    /** Add Size's picker (Q24). */
    public function addSizes(Request $request, InventoryPages $pages): SizeChoicesData
    {
        return $pages->addSizes($pages->storeId(InventoryForms::query($request, 'store')), InventoryForms::query($request, 'q'), InventoryForms::query($request, 'after'));
    }

    /**
     * The counts typed, one stocktake for all of them (`SetStock`): a refusal marks the rows it names
     * (`errors["rows.{size}"]`) and saves nothing; a save leaving orders short says so in amber.
     */
    public function counts(Request $request, SetStockHandler $handler, InventoryPages $pages): RedirectResponse
    {
        $counts = [];

        foreach (is_array($request->input('counts')) ? $request->input('counts') : [] as $id => $count) {
            $counts[(string) $id] = InventoryForms::whole($count);
        }

        return InventoryForms::act(
            $request,
            fn (): ?string => $pages->shortWarning($handler->handle(new SetStock($pages->storeId(InventoryForms::text($request, 'store')), $counts))),
            'inventory::admin_stock.toast.counts_saved',
        );
    }

    public function adjust(Request $request, AdjustStockHandler $handler, InventoryPages $pages): RedirectResponse
    {
        $direction = (string) InventoryForms::text($request, 'direction');

        return InventoryForms::act(
            $request,
            fn (): ?string => $pages->shortWarning($handler->handle(new AdjustStock(
                $pages->storeId(InventoryForms::text($request, 'store')),
                (string) InventoryForms::text($request, 'size'),
                $direction,
                InventoryForms::whole($request->input('quantity')),
                (string) InventoryForms::text($request, 'reason'),
                InventoryForms::text($request, 'note'),
            ))),
            $direction === 'REMOVE' ? 'inventory::admin_stock.toast.removed' : 'inventory::admin_stock.toast.added',
        );
    }

    /** One threshold for one size or the selected rows; empty for the store's default. */
    public function thresholds(Request $request, SetLowStockThresholdHandler $handler, InventoryPages $pages): RedirectResponse
    {
        $sizes = InventoryForms::texts($request, 'sizes');
        $typed = InventoryForms::text($request, 'threshold');
        $threshold = $typed === null ? null : InventoryForms::whole($typed);

        return InventoryForms::act($request, function () use ($handler, $pages, $request, $sizes, $threshold): ?string {
            $handler->handle(new SetLowStockThreshold($pages->storeId(InventoryForms::text($request, 'store')), array_fill_keys($sizes, $threshold)));

            return null;
        }, count($sizes) === 1 ? 'inventory::admin_stock.toast.threshold_saved' : 'inventory::admin_stock.toast.thresholds_saved');
    }

    /** Show / Hide Last Pieces for a product (no provider). */
    public function lastPieces(Request $request, SetEndingSoonHandler $handler, InventoryPages $pages): RedirectResponse
    {
        $on = $request->boolean('on');

        return InventoryForms::act($request, function () use ($handler, $pages, $request, $on): ?string {
            $handler->handle(new SetEndingSoon($pages->storeId(InventoryForms::text($request, 'store')), (string) InventoryForms::text($request, 'product'), $on));

            return null;
        }, $on ? 'inventory::admin_stock.toast.last_pieces_shown' : 'inventory::admin_stock.toast.last_pieces_hidden');
    }

    /** Counts on Stock for one size or the selected rows (a wired store). */
    public function countsOnStock(Request $request, SetStockDependentHandler $handler, InventoryPages $pages): RedirectResponse
    {
        $on = $request->boolean('on');

        return InventoryForms::act($request, function () use ($handler, $pages, $request, $on): ?string {
            $handler->handle(new SetStockDependent($pages->storeId(InventoryForms::text($request, 'store')), InventoryForms::texts($request, 'sizes'), $on));

            return null;
        }, $on ? 'inventory::admin_stock.toast.dependency_on' : 'inventory::admin_stock.toast.dependency_off');
    }
}
