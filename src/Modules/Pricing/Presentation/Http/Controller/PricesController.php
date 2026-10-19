<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Pricing\Application\Command\RemoveRetailPrice\RemoveRetailPrice;
use Modules\Pricing\Application\Command\RemoveRetailPrice\RemoveRetailPriceHandler;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPrice;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPriceHandler;
use Modules\Pricing\Application\Query\ListPrices\ListPrices;
use Modules\Pricing\Presentation\Http\Resource\PricesPage;
use Modules\Pricing\Presentation\Http\Resource\PricingPages;
use Modules\Pricing\Presentation\Http\Resource\SizePricesData;

/**
 * **Prices** (pricing.md §4.3.3 P1): the store's sizes and their prices, the size drawer (`?size=`, Q3),
 * and the prices typed in its boxes - saved together, all or none (Q2) - or one removed. The save serves
 * Needs a Price and the product page too: every box of a retail price sends here. Each handler asks for
 * its own job in the store (§3); this checks nothing.
 */
final readonly class PricesController
{
    /** @var list<string> */
    public const array WORDS = ['pricing::admin', 'pricing::admin_prices', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, PricingPages $pages): Response
    {
        $filter = PricingForms::word($request, 'filter') ?? ListPrices::ALL;
        $store = PricingForms::query($request, 'store');
        $size = PricingForms::query($request, 'size');

        return $this->page->render('Pricing/Admin/Prices', [
            ...PricingForms::lazy(PricesPage::class, fn (): PricesPage => $pages->prices($store, PricingForms::query($request, 'q'), $filter, PricingForms::query($request, 'after'))),
            // The drawer's own read, only while the address names a size (Q3).
            'drawer' => static fn (): ?array => $size === null ? null : $pages->sizePrices($pages->store($store)->id, $size)->toArray(),
        ], self::WORDS);
    }

    /** A size's prices, for the bands dialog wherever it opens (P1, the drawer, the product page). */
    public function size(Request $request, PricingPages $pages): SizePricesData
    {
        return $pages->sizePrices($pages->storeId(PricingForms::query($request, 'store')), (string) PricingForms::query($request, 'size'));
    }

    /**
     * The prices typed, one command for all of them (`SetRetailPrice`): a refusal marks the rows it names
     * (`errors["rows.{size}"]`) and saves nothing.
     */
    public function save(Request $request, SetRetailPriceHandler $handler, PricingPages $pages): RedirectResponse
    {
        $prices = PricingForms::map($request, 'prices');

        return PricingForms::act(
            $request,
            fn () => $handler->handle(new SetRetailPrice($pages->storeId(PricingForms::text($request, 'store')), $prices)),
            count($prices) === 1 ? 'pricing::admin.toast.price_saved' : 'pricing::admin.toast.prices_saved',
        );
    }

    public function remove(Request $request, RemoveRetailPriceHandler $handler, PricingPages $pages): RedirectResponse
    {
        return PricingForms::act(
            $request,
            fn () => $handler->handle(new RemoveRetailPrice($pages->storeId(PricingForms::text($request, 'store')), PricingForms::texts($request, 'sizes'))),
            'pricing::admin.toast.price_removed',
        );
    }
}
