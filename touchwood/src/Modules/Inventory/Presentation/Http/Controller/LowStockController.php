<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Inventory\Presentation\Http\Resource\InventoryPages;
use Modules\Inventory\Presentation\Http\Resource\LowStockPage;

/**
 * **Low Stock** (inventory.md §4.3 I2): the sizes at or below their threshold, the fewest left first; its
 * changes go through Stock's (`inventory.admin.stock.*`). The read asks for Manage Stock (§3); this checks
 * nothing.
 */
final readonly class LowStockController
{
    /** @var list<string> */
    public const array WORDS = ['inventory::admin_stock', 'inventory::admin_low', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, InventoryPages $pages): Response
    {
        return $this->page->render('Inventory/Admin/LowStock', InventoryForms::lazy(LowStockPage::class, fn (): LowStockPage => $pages->lowStock(
            InventoryForms::query($request, 'store'),
            InventoryForms::query($request, 'after'),
        )), self::WORDS);
    }
}
