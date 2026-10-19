<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSale;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSaleHandler;
use Modules\Pricing\Application\Command\EndSale\EndSale;
use Modules\Pricing\Application\Command\EndSale\EndSaleHandler;
use Modules\Pricing\Application\Command\RemoveSale\RemoveSale;
use Modules\Pricing\Application\Command\RemoveSale\RemoveSaleHandler;
use Modules\Pricing\Presentation\Http\Resource\OffersPage;
use Modules\Pricing\Presentation\Http\Resource\OverlapNoteData;
use Modules\Pricing\Presentation\Http\Resource\PricingPages;
use Modules\Pricing\Presentation\Http\Resource\ProductResultsData;
use Modules\Pricing\Presentation\Http\Resource\SaleViewData;

/**
 * **Offers** (pricing.md §4.3.3 P2; "Offer" is the panel's word for a sale, §9.4): the store's offers, the
 * offer dialog's reads - the products, their sizes, the note as typed - and every change: add, change a
 * scheduled one, change a running one's end, end, remove. Each handler asks for **Edit Prices and Sales** in
 * the offer's store (§3); this checks nothing.
 */
final readonly class OffersController
{
    /** @var list<string> */
    public const array WORDS = ['pricing::admin', 'pricing::admin_offers', 'admin'];

    /** The dialog's fields, where a refusal naming one is said. */
    private const array FIELDS = ['product', 'percent', 'price', 'starts_at', 'ends_at'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, PricingPages $pages): Response
    {
        $state = PricingForms::word($request, 'state');

        return $this->page->render('Pricing/Admin/Offers', PricingForms::lazy(OffersPage::class, fn (): OffersPage => $pages->offers(
            PricingForms::query($request, 'store'),
            $state === 'ALL' ? null : $state,
            PricingForms::word($request, 'sort') ?? 'BY_STATE',
            PricingForms::query($request, 'product'),
            PricingForms::query($request, 'sale'),
            PricingForms::query($request, 'after'),
        )), self::WORDS);
    }

    /** View Offer: one offer, every field (`ViewSale`). */
    public function show(string $sale, PricingPages $pages): SaleViewData
    {
        return $pages->saleView($sale);
    }

    /** The Product filter's choices: the products with offers in the store. */
    public function filterProducts(Request $request, PricingPages $pages): ProductResultsData
    {
        return $pages->offerProducts($pages->storeId(PricingForms::query($request, 'store')), PricingForms::query($request, 'q'), PricingForms::query($request, 'after'));
    }

    /** The offer dialog's Product: the store's ready products switched on (Q13). */
    public function products(Request $request, PricingPages $pages): ProductResultsData
    {
        return $pages->saleProducts($pages->storeId(PricingForms::query($request, 'store')), PricingForms::query($request, 'q'), PricingForms::query($request, 'after'));
    }

    /** The offer dialog's Sizes: the product's sizes switched on, with their prices (Q39). */
    public function sizes(Request $request, PricingPages $pages): JsonResponse
    {
        return response()->json($pages->saleSizes($pages->storeId(PricingForms::query($request, 'store')), (string) PricingForms::query($request, 'product')));
    }

    /** The note as typed (`OverlapsFor`, Q11): refreshed by the dialog 300 ms after a change. */
    public function note(Request $request, PricingPages $pages): OverlapNoteData
    {
        return $pages->note(
            $pages->storeId(PricingForms::query($request, 'store')),
            PricingForms::query($request, 'product'),
            PricingForms::texts($request, 'sizes'),
            PricingForms::query($request, 'starts_at'),
            PricingForms::query($request, 'ends_at'),
            PricingForms::query($request, 'sale'),
            PricingForms::query($request, 'percent'),
            PricingForms::query($request, 'price'),
            PricingForms::flag($request, 'always_wins'),
        );
    }

    public function add(Request $request, AddSaleHandler $handler, PricingPages $pages): RedirectResponse
    {
        return PricingForms::act($request, fn () => $handler->handle(new AddSale(
            $pages->storeId(PricingForms::text($request, 'store')),
            PricingForms::text($request, 'product'),
            PricingForms::texts($request, 'sizes'),
            PricingForms::text($request, 'percent'),
            PricingForms::text($request, 'price'),
            PricingForms::text($request, 'starts_at'),
            PricingForms::text($request, 'ends_at'),
            PricingForms::flag($request, 'always_wins'),
        )), 'pricing::admin.toast.offer_added', self::FIELDS);
    }

    /** A scheduled offer changed in full - its product and sizes kept (§1.3). */
    public function change(Request $request, string $sale, ChangeSaleHandler $handler): RedirectResponse
    {
        return PricingForms::act($request, fn () => $handler->handle(new ChangeSale(
            $sale,
            PricingForms::text($request, 'percent'),
            PricingForms::text($request, 'price'),
            PricingForms::text($request, 'starts_at'),
            PricingForms::text($request, 'ends_at'),
            PricingForms::flag($request, 'always_wins'),
        )), 'pricing::admin.toast.offer_changed', self::FIELDS);
    }

    /** Change End: a running offer's end alone, the rest sent back as it is (§4.1). */
    public function changeEnd(Request $request, string $sale, ChangeSaleHandler $handler, PricingPages $pages): RedirectResponse
    {
        return PricingForms::act($request, function () use ($request, $sale, $handler, $pages): void {
            $typed = $pages->saleAsTyped($sale);
            $handler->handle(new ChangeSale($sale, $typed['percent'], $typed['price'], null, PricingForms::text($request, 'ends_at'), $typed['alwaysWins']));
        }, 'pricing::admin.toast.offer_changed', ['ends_at']);
    }

    public function end(Request $request, string $sale, EndSaleHandler $handler): RedirectResponse
    {
        return PricingForms::act($request, fn () => $handler->handle(new EndSale($sale)), 'pricing::admin.toast.offer_ended');
    }

    public function remove(Request $request, string $sale, RemoveSaleHandler $handler): RedirectResponse
    {
        return PricingForms::act($request, fn () => $handler->handle(new RemoveSale($sale)), 'pricing::admin.toast.offer_removed');
    }
}
