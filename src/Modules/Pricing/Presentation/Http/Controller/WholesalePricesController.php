<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Presentation\Http\Resource\PricingPages;
use Modules\Pricing\Presentation\Http\Resource\SizeResultsData;
use Modules\Pricing\Presentation\Http\Resource\WholesalePage;

/**
 * **Wholesale Prices** (pricing.md §4.3.3 P4): the sizes with bands in the store, Add Wholesale Prices' size
 * picker, and a size's bands saved all at once - none removes them (`SetWholesalePrices`). The handler asks
 * for **Edit Wholesale Prices** in the store (§3); this checks nothing.
 */
final readonly class WholesalePricesController
{
    /** @var list<string> */
    public const array WORDS = ['pricing::admin', 'pricing::admin_wholesale', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, PricingPages $pages): Response
    {
        return $this->page->render('Pricing/Admin/WholesalePrices', PricingForms::lazy(WholesalePage::class, fn (): WholesalePage => $pages->wholesale(
            PricingForms::query($request, 'store'),
            PricingForms::query($request, 'q'),
            PricingForms::query($request, 'after'),
        )), self::WORDS);
    }

    public function sizes(Request $request, PricingPages $pages): SizeResultsData
    {
        return $pages->wholesaleSizes($pages->storeId(PricingForms::query($request, 'store')), PricingForms::query($request, 'q'), PricingForms::query($request, 'after'));
    }

    /**
     * The bands as typed, each row its quantity and price; none removes them. Removing them all is
     * **Remove Wholesale Prices** and says so.
     */
    public function save(Request $request, SetWholesalePricesHandler $handler, PricingPages $pages): RedirectResponse
    {
        $bands = [];
        $repeated = false;

        foreach (is_array($request->input('bands')) ? $request->input('bands') : [] as $band) {
            if (is_array($band) && (is_string($band['from'] ?? null) || is_int($band['from'] ?? null)) && is_string($band['price'] ?? null)) {
                $from = trim((string) $band['from']);
                // Two bands from the same quantity are refused, never merged into one (the review of step 3).
                $repeated = $repeated || array_key_exists($from, $bands);
                $bands[$from] = trim($band['price']);
            }
        }

        $size = (string) PricingForms::text($request, 'size');

        return PricingForms::act(
            $request,
            function () use ($handler, $pages, $request, $size, $bands, $repeated): void {
                if ($repeated) {
                    throw new InvalidPricingInput('quantity', $size === '' ? [] : [$size]);
                }

                $handler->handle(new SetWholesalePrices($pages->storeId(PricingForms::text($request, 'store')), $size, $bands));
            },
            $bands === [] ? 'pricing::admin.toast.wholesale_removed' : 'pricing::admin.toast.wholesale_saved',
        );
    }
}
