<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Pricing\Presentation\Http\Resource\NeedsAPricePage;
use Modules\Pricing\Presentation\Http\Resource\PricingPages;

/**
 * **Needs a Price** (pricing.md §4.3.3 P5): the sizes switched on in the store with no price, for whoever
 * edits prices there; the boxes save through Prices' own save (`pricing.admin.prices.save`). The read asks
 * for **Edit Prices and Sales** (§3); this checks nothing.
 */
final readonly class NeedsAPriceController
{
    /** @var list<string> */
    public const array WORDS = ['pricing::admin', 'pricing::admin_needs_a_price', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, PricingPages $pages): Response
    {
        return $this->page->render('Pricing/Admin/NeedsAPrice', PricingForms::lazy(NeedsAPricePage::class, fn (): NeedsAPricePage => $pages->needsAPrice(
            PricingForms::query($request, 'store'),
            PricingForms::query($request, 'q'),
            PricingForms::query($request, 'after'),
        )), self::WORDS);
    }
}
