<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\ChangeCategoryDiscount\ChangeCategoryDiscount;
use Modules\Pricing\Application\Command\ChangeCategoryDiscount\ChangeCategoryDiscountHandler;
use Modules\Pricing\Application\Command\EndCategoryDiscount\EndCategoryDiscount;
use Modules\Pricing\Application\Command\EndCategoryDiscount\EndCategoryDiscountHandler;
use Modules\Pricing\Application\Command\RemoveCategoryDiscount\RemoveCategoryDiscount;
use Modules\Pricing\Application\Command\RemoveCategoryDiscount\RemoveCategoryDiscountHandler;
use Modules\Pricing\Presentation\Http\Resource\CategoryDiscountPage;
use Modules\Pricing\Presentation\Http\Resource\DiscountPreviewData;
use Modules\Pricing\Presentation\Http\Resource\DiscountsPage;
use Modules\Pricing\Presentation\Http\Resource\PricingPages;
use Modules\Pricing\Presentation\Http\Resource\ProductResultsData;

/**
 * **Category Discounts** (pricing.md §4.3.3 P3): the store's discounts, a discount's page, the dialog's reads
 * - the categories, the products it can leave out, the preview as typed - and every change. Each handler
 * asks for **Manage Category Discounts** in the discount's store (§3); this checks nothing.
 */
final readonly class CategoryDiscountsController
{
    /**
     * The list's state and sort switches read Offers' words (pricing::admin_offers.filters), so that file is
     * sent too: without it the switches showed their keys (the phone audit, 2026-10-29).
     *
     * @var list<string>
     */
    public const array WORDS = ['pricing::admin', 'pricing::admin_discounts', 'pricing::admin_offers', 'admin'];

    /** The dialog's fields, where a refusal naming one is said. */
    private const array FIELDS = ['category', 'percent', 'amount', 'lines', 'starts_at', 'ends_at', 'left_out'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, PricingPages $pages): Response
    {
        $state = PricingForms::word($request, 'state');

        return $this->page->render('Pricing/Admin/CategoryDiscounts', PricingForms::lazy(DiscountsPage::class, fn (): DiscountsPage => $pages->discounts(
            PricingForms::query($request, 'store'),
            $state === 'ALL' ? null : $state,
            PricingForms::word($request, 'sort') ?? 'BY_STATE',
            PricingForms::query($request, 'after'),
        )), self::WORDS);
    }

    public function show(Request $request, string $discount, PricingPages $pages): Response
    {
        return $this->page->render('Pricing/Admin/CategoryDiscount', PricingForms::lazy(CategoryDiscountPage::class, fn (): CategoryDiscountPage => $pages->discountPage($discount, PricingForms::query($request, 'after'))), self::WORDS);
    }

    public function categories(Request $request, PricingPages $pages): JsonResponse
    {
        return response()->json($pages->categories($pages->storeId(PricingForms::query($request, 'store'))));
    }

    public function leftOut(Request $request, PricingPages $pages): ProductResultsData
    {
        return $pages->leftOut($pages->storeId(PricingForms::query($request, 'store')), (string) PricingForms::query($request, 'category'), PricingForms::query($request, 'q'), PricingForms::query($request, 'after'));
    }

    /** The preview as typed (`PreviewCategoryDiscount`): refreshed by the dialog 300 ms after a change. */
    public function preview(Request $request, PricingPages $pages): DiscountPreviewData
    {
        return $pages->preview(
            $pages->storeId(PricingForms::query($request, 'store')),
            (string) PricingForms::query($request, 'category'),
            PricingForms::query($request, 'percent'),
            PricingForms::query($request, 'amount'),
            (string) PricingForms::word($request, 'lines'),
            PricingForms::texts($request, 'left_out'),
            PricingForms::query($request, 'starts_at'),
            PricingForms::query($request, 'ends_at'),
            PricingForms::query($request, 'discount'),
            PricingForms::query($request, 'after'),
        );
    }

    public function add(Request $request, AddCategoryDiscountHandler $handler, PricingPages $pages): RedirectResponse
    {
        return PricingForms::act($request, fn () => $handler->handle(new AddCategoryDiscount(
            $pages->storeId(PricingForms::text($request, 'store')),
            (string) PricingForms::text($request, 'category'),
            PricingForms::text($request, 'percent'),
            PricingForms::text($request, 'amount'),
            (string) PricingForms::text($request, 'lines'),
            PricingForms::text($request, 'starts_at'),
            PricingForms::text($request, 'ends_at'),
            PricingForms::flag($request, 'always_wins'),
            PricingForms::texts($request, 'left_out'),
        )), 'pricing::admin.toast.discount_added', self::FIELDS);
    }

    public function change(Request $request, string $discount, ChangeCategoryDiscountHandler $handler): RedirectResponse
    {
        return PricingForms::act($request, fn () => $handler->handle(new ChangeCategoryDiscount(
            $discount,
            (string) PricingForms::text($request, 'category'),
            PricingForms::text($request, 'percent'),
            PricingForms::text($request, 'amount'),
            (string) PricingForms::text($request, 'lines'),
            PricingForms::text($request, 'starts_at'),
            PricingForms::text($request, 'ends_at'),
            PricingForms::flag($request, 'always_wins'),
            PricingForms::texts($request, 'left_out'),
        )), 'pricing::admin.toast.discount_changed', self::FIELDS);
    }

    /** Change End: a running discount's end alone, the rest sent back as it is (§4.1). */
    public function changeEnd(Request $request, string $discount, ChangeCategoryDiscountHandler $handler, PricingPages $pages): RedirectResponse
    {
        return PricingForms::act($request, function () use ($request, $discount, $handler, $pages): void {
            $typed = $pages->discountAsTyped($discount);
            $handler->handle(new ChangeCategoryDiscount(
                $discount,
                $typed['categoryId'],
                $typed['percent'],
                $typed['amount'],
                $typed['lines'],
                null,
                PricingForms::text($request, 'ends_at'),
                $typed['alwaysWins'],
                $typed['leftOut'],
            ));
        }, 'pricing::admin.toast.discount_changed', ['ends_at']);
    }

    public function end(Request $request, string $discount, EndCategoryDiscountHandler $handler): RedirectResponse
    {
        return PricingForms::act($request, fn () => $handler->handle(new EndCategoryDiscount($discount)), 'pricing::admin.toast.discount_ended');
    }

    public function remove(Request $request, string $discount, RemoveCategoryDiscountHandler $handler): RedirectResponse
    {
        // Removed from its own page, which is gone with it: the list of its store follows.
        $list = PricingForms::text($request, 'list_store');

        return PricingForms::act($request, fn () => $handler->handle(new RemoveCategoryDiscount($discount)), 'pricing::admin.toast.discount_removed', [], $list === null ? null : route('pricing.admin.category-discounts', ['store' => $list], false));
    }
}
