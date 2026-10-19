<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\BrandDto;
use Modules\Catalog\Public\Dto\CategoryPathDto;
use Modules\Catalog\Public\Dto\FoundProductDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\AssignmentRow;
use Modules\Promotions\Application\Query\CouponRow;
use Modules\Promotions\Application\Query\CouponUseRow;
use Modules\Promotions\Application\Query\CouponView;
use Modules\Promotions\Application\Query\ListCouponAssignments\ListCouponAssignments;
use Modules\Promotions\Application\Query\ListCouponAssignments\ListCouponAssignmentsHandler;
use Modules\Promotions\Application\Query\ListCoupons\ListCoupons;
use Modules\Promotions\Application\Query\ListCoupons\ListCouponsHandler;
use Modules\Promotions\Application\Query\ListCouponUses\ListCouponUses;
use Modules\Promotions\Application\Query\ListCouponUses\ListCouponUsesHandler;
use Modules\Promotions\Application\Query\ListSegments\ListSegments;
use Modules\Promotions\Application\Query\ListSegments\ListSegmentsHandler;
use Modules\Promotions\Application\Query\PromotionsActions\PromotionsActionsForReader;
use Modules\Promotions\Application\Query\RuleView;
use Modules\Promotions\Application\Query\SegmentRow;
use Modules\Promotions\Application\Query\ViewCoupon\ViewCoupon;
use Modules\Promotions\Application\Query\ViewCoupon\ViewCouponHandler;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\CouponState;
use Modules\Promotions\Public\Enums\SegmentAudience;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The coupon screens' data (promotions.md §4.1 M1, M2), built from Promotions' Application queries -
 * which authorize - and nothing of its own deciding: what a reader may do comes from
 * PromotionsActionsForReader, never from here.
 */
final readonly class CouponPages
{
    /** Every job that opens the Marketing screens of a store (§3): MarketingScreen's. */
    public const array JOBS = MarketingScreen::JOBS;

    /** At most 20 pages of 50 segments for Give to a Segment's choices. */
    private const int SEGMENT_PAGES = 20;

    private const string CEILING = 'pricing.ceiling.max_discount_percent';

    public function __construct(
        private MarketingScreen $screen,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private PromotionsActionsForReader $actions,
    ) {}

    /**
     * M1.
     *
     * @throws Unauthorized
     */
    public function list(?string $storeCode, ?string $state, ?string $code, ?string $beforeAt, ?string $beforeId, ListCouponsHandler $list): CouponsListPage
    {
        [$store, $offered] = $this->screen->store($storeCode);
        $exponent = $this->exponent($store);
        $wanted = $state === null ? null : CouponState::tryFrom(strtoupper($state));
        $page = $list->handle(new ListCoupons($store->id, $wanted, $code, MarketingScreen::before($beforeAt), $beforeId));

        return new CouponsListPage(
            array_map(fn (CouponRow $row): CouponRowData => $this->row($row, $exponent), $page->rows),
            $page->nextAt === null ? null : MarketingScreen::cursor($page->nextAt),
            $page->nextId,
            $wanted?->value,
            $code,
            $this->actions->forStore($store->storeId())->mayManageCoupons,
            ...$this->screen->shown($store),
            stores: $this->screen->options($offered),
        );
    }

    /**
     * M2, a new coupon in the store chosen.
     *
     * @throws Unauthorized
     */
    public function new(?string $storeCode, ?string $productSearch): CouponPage
    {
        [$store] = $this->screen->store($storeCode);

        return $this->page($store, null, 'details', $productSearch, null, null, null, null, null, null);
    }

    /**
     * M2, a coupon's page with one tab open.
     *
     * @throws Unauthorized|CouponNotFound
     */
    public function coupon(
        string $couponId,
        string $tab,
        ?string $productSearch,
        ?string $usesBeforeAt,
        ?int $usesBeforeId,
        ?string $customersBeforeAt,
        ?string $customersBeforeId,
        ViewCouponHandler $view,
        ListCouponUsesHandler $uses,
        ListCouponAssignmentsHandler $assignments,
        ListSegmentsHandler $segments,
    ): CouponPage {
        $coupon = $view->handle(new ViewCoupon($couponId));
        $store = $this->platform->store(StoreId::fromString($coupon->storeId)) ?? throw new Unauthorized(PromotionsPermissions::VIEW);
        $assigned = $coupon->coupon->eligibility === CouponEligibility::Assigned;
        $tab = in_array($tab, ['details', 'uses', 'customers'], true) && ($tab !== 'customers' || $assigned) ? $tab : 'details';
        $usesPage = $tab === 'uses' ? $uses->handle(new ListCouponUses($couponId, MarketingScreen::before($usesBeforeAt), $usesBeforeId)) : null;
        $customersPage = $tab === 'customers' ? $assignments->handle(new ListCouponAssignments($couponId, MarketingScreen::before($customersBeforeAt), $customersBeforeId)) : null;
        $segmentRows = $tab === 'customers' ? self::everySegment($store->id, $segments) : [];

        return $this->page($store, $coupon, $tab, $productSearch, $usesPage?->rows, $usesPage?->nextAt, $usesPage?->nextId, $customersPage?->rows, $customersPage?->nextAt, $customersPage?->nextCustomerId, $segmentRows);
    }

    /**
     * @param  list<CouponUseRow>|null  $uses
     * @param  list<AssignmentRow>|null  $customers
     * @param  list<SegmentRow>  $segments
     */
    private function page(
        StoreDto $store,
        ?CouponView $coupon,
        string $tab,
        ?string $productSearch,
        ?array $uses,
        ?DateTimeImmutable $usesNextAt,
        ?int $usesNextId,
        ?array $customers,
        ?DateTimeImmutable $customersNextAt,
        ?string $customersNextId,
        array $segments = [],
    ): CouponPage {
        $exponent = $this->exponent($store);
        $locale = $this->screen->locale();

        return new CouponPage(
            $coupon === null ? null : $this->form($coupon, $exponent),
            $tab,
            array_map(fn (CouponUseRow $use): CouponUseData => new CouponUseData(
                $use->id,
                $use->orderNumber,
                CustomerNameData::of($use->customer),
                MoneyData::of($use->discount, $exponent),
                MarketingScreen::moment($use->usedAt),
            ), $uses ?? []),
            $usesNextAt === null ? null : MarketingScreen::cursor($usesNextAt),
            $usesNextId,
            array_map(fn (AssignmentRow $row): AssignmentData => new AssignmentData(
                CustomerNameData::of($row->customer),
                MarketingScreen::moment($row->assignedAt),
                $row->segmentId === null ? null : ($locale === 'en' ? $row->segmentNameEn : $row->segmentNameAr),
                $row->uses,
            ), $customers ?? []),
            $customersNextAt === null ? null : MarketingScreen::cursor($customersNextAt),
            $customersNextId,
            array_map(fn (CategoryPathDto $category): ChoiceData => new ChoiceData(
                $category->id,
                $locale === 'en' ? $category->nameEn : $category->nameAr,
                self::path($category->path, $locale),
            ), $this->catalog->activeCategories()),
            array_map(static fn (BrandDto $brand): ChoiceData => new ChoiceData($brand->id, $locale === 'en' ? $brand->nameEn : $brand->nameAr, null), $this->catalog->activeBrands()),
            $productSearch === null ? [] : array_map(
                static fn (FoundProductDto $product): ChoiceData => new ChoiceData(
                    $product->productId,
                    $locale === 'en' ? ($product->nameEn ?? $product->nameAr) : $product->nameAr,
                    $product->codes === [] ? null : implode(', ', array_slice($product->codes, 0, 3)),
                ),
                $this->catalog->searchProducts($store->storeId(), $productSearch, true, limit: 20)->rows,
            ),
            $coupon === null ? [] : $this->segments($coupon, $segments, $locale),
            $this->platform->setting(self::CEILING, $store->storeId())->int(),
            $store->currencyCode,
            $exponent,
            $this->actions->forStore($store->storeId())->mayManageCoupons,
            ...$this->screen->shown($store),
        );
    }

    private function form(CouponView $view, int $exponent): CouponFormData
    {
        $coupon = $view->coupon;
        $locale = $this->screen->locale();

        return new CouponFormData(
            $coupon->id,
            $coupon->code,
            $coupon->nameAr,
            $coupon->nameEn,
            $coupon->eligibility->value,
            $coupon->discountKind->value,
            $coupon->percent,
            $view->cap === null ? null : MoneyData::decimal($view->cap->minorUnits, $exponent),
            $coupon->amount === null ? null : MoneyData::decimal($coupon->amount->minorUnits, $exponent),
            $view->minimum === null ? null : MoneyData::decimal($view->minimum->minorUnits, $exponent),
            MarketingScreen::moment($coupon->startsAt),
            $coupon->endsAt === null ? null : MarketingScreen::moment($coupon->endsAt),
            $view->maxUsesPerCustomer,
            $coupon->maxUsesTotal,
            $view->allowWithPoints,
            $view->onReducedLines,
            $view->forRetail,
            $view->forWholesale,
            $view->forPublic,
            $view->forCompany,
            array_map(static fn (RuleView $rule): RuleData => new RuleData(
                $rule->kind,
                $rule->target,
                $rule->targetId,
                $locale === 'en' ? ($rule->nameEn ?? $rule->nameAr) : ($rule->nameAr ?? $rule->nameEn),
                $locale === 'en' ? ($rule->pathEn ?? $rule->pathAr) : ($rule->pathAr ?? $rule->pathEn),
                $rule->sold,
            ), $view->rules),
            $coupon->active,
            $coupon->state->value,
            $coupon->usesTotal,
            $view->assignedCount,
        );
    }

    private function row(CouponRow $row, int $exponent): CouponRowData
    {
        return new CouponRowData(
            $row->id,
            $row->code,
            $this->screen->locale() === 'en' ? $row->nameEn : $row->nameAr,
            $row->eligibility->value,
            $row->discountKind->value,
            $row->percent,
            $row->amount === null ? null : MoneyData::of($row->amount, $exponent),
            $row->cap === null ? null : MoneyData::of($row->cap, $exponent),
            MarketingScreen::moment($row->startsAt),
            $row->endsAt === null ? null : MarketingScreen::moment($row->endsAt),
            $row->usesTotal,
            $row->maxUsesTotal,
            $row->state->value,
        );
    }

    /**
     * Every segment of the store, a page at a time (owner's Q14: any segment the coupon allows may be
     * chosen), up to SEGMENT_PAGES pages - a store with more is far past what one dialog lists.
     *
     * @return list<SegmentRow>
     */
    private static function everySegment(string $storeId, ListSegmentsHandler $segments): array
    {
        $rows = [];
        $at = null;
        $id = null;

        for ($page = 0; $page < self::SEGMENT_PAGES; $page++) {
            $found = $segments->handle(new ListSegments($storeId, $at, $id, perPage: 50));
            $rows = [...$rows, ...$found->rows];

            if ($found->nextAt === null || $found->nextId === null) {
                break;
            }

            [$at, $id] = [$found->nextAt, $found->nextId];
        }

        return $rows;
    }

    /**
     * The segments an assigned coupon may be given to: those whose audience it allows (owner's Q14).
     *
     * @param  list<SegmentRow>  $segments
     * @return list<SegmentChoiceData>
     */
    private function segments(CouponView $coupon, array $segments, string $locale): array
    {
        $allowed = static fn (SegmentRow $segment): bool => $segment->audience === SegmentAudience::Company ? $coupon->forCompany : $coupon->forPublic;

        return array_values(array_map(
            static fn (SegmentRow $segment): SegmentChoiceData => new SegmentChoiceData(
                $segment->id,
                $locale === 'en' ? $segment->nameEn : $segment->nameAr,
                $segment->audience->value,
                $segment->memberCount,
            ),
            array_filter($segments, $allowed),
        ));
    }

    private function exponent(StoreDto $store): int
    {
        return $store->currencyExponent;
    }

    /**
     * A category's path in the page's language, its own name left out: "Kitchens › Drawers" for Handles.
     *
     * @param  list<TranslatedTextDto>  $path
     */
    private static function path(array $path, string $locale): ?string
    {
        $above = array_slice($path, 0, -1);

        return $above === [] ? null : implode(' › ', array_map(static fn (TranslatedTextDto $name): string => $name->in($locale), $above));
    }
}
