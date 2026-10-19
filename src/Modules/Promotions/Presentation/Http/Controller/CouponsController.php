<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Controller;

use App\Http\AdminArea;
use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Application\Command\AssignCoupon\AssignCoupon;
use Modules\Promotions\Application\Command\AssignCoupon\AssignCouponHandler;
use Modules\Promotions\Application\Command\ChangeCoupon\ChangeCoupon;
use Modules\Promotions\Application\Command\ChangeCoupon\ChangeCouponHandler;
use Modules\Promotions\Application\Command\CreateCoupon\CreateCoupon;
use Modules\Promotions\Application\Command\CreateCoupon\CreateCouponHandler;
use Modules\Promotions\Application\Command\DeleteCoupon\DeleteCoupon;
use Modules\Promotions\Application\Command\DeleteCoupon\DeleteCouponHandler;
use Modules\Promotions\Application\Command\RemoveAssignment\RemoveAssignment;
use Modules\Promotions\Application\Command\RemoveAssignment\RemoveAssignmentHandler;
use Modules\Promotions\Application\Command\SwitchCoupon\SwitchCoupon;
use Modules\Promotions\Application\Command\SwitchCoupon\SwitchCouponHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\ListCouponAssignments\ListCouponAssignmentsHandler;
use Modules\Promotions\Application\Query\ListCoupons\ListCouponsHandler;
use Modules\Promotions\Application\Query\ListCouponUses\ListCouponUsesHandler;
use Modules\Promotions\Application\Query\ListSegments\ListSegmentsHandler;
use Modules\Promotions\Application\Query\ViewCoupon\ViewCoupon;
use Modules\Promotions\Application\Query\ViewCoupon\ViewCouponHandler;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Presentation\Http\Resource\CouponPages;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/*
| Promotions' coupon screens (promotions.md §4.1 M1, M2): the store's coupons, one coupon's page with
| its Details, Uses and Customers tabs, and every change behind their buttons.
|
| Who may open or change any of these is Promotions' answer, never the routing's: each read asks for one
| of the Marketing jobs in the coupon's store, each change for `promotions.coupon.manage` (§3).
*/
final readonly class CouponsController
{
    /** @var list<string> */
    private const array WORDS = ['promotions::admin_coupons', 'promotions::errors', 'admin'];

    private const string LIST = '/'.AdminArea::PREFIX.'/coupons';

    // What asks who is acting - the pages, the handlers - is taken by each action, never kept here: a
    // controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    /** M1. */
    public function index(Request $request, CouponPages $pages, ListCouponsHandler $list): Response
    {
        return $this->page->render('Promotions/Admin/Coupons/Index', $pages->list(
            self::query($request, 'store'),
            self::query($request, 'state'),
            self::query($request, 'code'),
            self::query($request, 'before_at'),
            self::query($request, 'before_id'),
            $list,
        )->toArray(), self::WORDS);
    }

    /** M2, a new coupon. */
    public function create(Request $request, CouponPages $pages): Response
    {
        return $this->page->render('Promotions/Admin/Coupons/Show', $pages->new(self::query($request, 'store'), self::search($request))->toArray(), self::WORDS);
    }

    /** M2. */
    public function show(
        Request $request,
        string $coupon,
        CouponPages $pages,
        ViewCouponHandler $view,
        ListCouponUsesHandler $uses,
        ListCouponAssignmentsHandler $assignments,
        ListSegmentsHandler $segments,
    ): Response {
        $usesBefore = self::query($request, 'uses_before_id');

        return $this->page->render('Promotions/Admin/Coupons/Show', $pages->coupon(
            $coupon,
            self::query($request, 'tab') ?? 'details',
            self::search($request),
            self::query($request, 'uses_before_at'),
            $usesBefore !== null && ctype_digit($usesBefore) && strlen($usesBefore) <= 18 ? (int) $usesBefore : null,
            self::query($request, 'customers_before_at'),
            self::query($request, 'customers_before_id'),
            $view,
            $uses,
            $assignments,
            $segments,
        )->toArray(), self::WORDS);
    }

    /** M2's Save Coupon on a new coupon, in the store the form names; its page opens. */
    public function store(Request $request, StoreChoices $choices, CreateCouponHandler $handler): RedirectResponse
    {
        $id = null;

        $answer = self::act($request, function () use ($request, $choices, $handler, &$id): void {
            $store = $choices->chosen(CouponForm::text($request, 'store'), PromotionsPermissions::COUPON_MANAGE) ?? throw new Unauthorized(PromotionsPermissions::COUPON_MANAGE);
            $id = $handler->handle(new CreateCoupon(
                $store->id,
                CouponForm::text($request, 'code') ?? '',
                CouponForm::fields($request, $store->currencyCode, $store->currencyExponent),
                $request->boolean('active', true),
            ));
        }, 'promotions::admin_coupons.toast.created');

        return is_string($id) ? redirect(self::LIST.'/'.$id)->with('status', __('promotions::admin_coupons.toast.created')) : $answer;
    }

    /** M2's Save Coupon. */
    public function update(Request $request, string $coupon, ViewCouponHandler $view, PlatformApi $platform, ChangeCouponHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $coupon, $view, $platform, $handler): void {
            $store = self::storeOf($coupon, $view, $platform);
            $handler->handle(new ChangeCoupon($coupon, CouponForm::fields($request, $store->currencyCode, $store->currencyExponent)));
        }, 'promotions::admin_coupons.toast.saved');
    }

    /** M2's Turn Off / Turn On, saved at once. */
    public function switch(Request $request, string $coupon, SwitchCouponHandler $handler): RedirectResponse
    {
        $on = $request->boolean('active');

        return self::act(
            $request,
            fn () => $handler->handle(new SwitchCoupon($coupon, $on)),
            $on ? 'promotions::admin_coupons.toast.on' : 'promotions::admin_coupons.toast.off',
        );
    }

    /** M2's Delete Coupon…, only while unused (§1.1); back to the list. */
    public function destroy(Request $request, string $coupon, DeleteCouponHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeleteCoupon($coupon));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect(self::LIST)->with('status', __('promotions::admin_coupons.toast.deleted'));
    }

    /** M2's Give to a Segment… (owner's Q14). */
    public function assign(Request $request, string $coupon, AssignCouponHandler $handler): RedirectResponse
    {
        return self::act(
            $request,
            fn () => $handler->handle(new AssignCoupon($coupon, CouponForm::text($request, 'segment') ?? '')),
            'promotions::admin_coupons.toast.given',
        );
    }

    /** M2's Remove on an unused assignment. */
    public function removeAssignment(Request $request, string $coupon, string $customer, RemoveAssignmentHandler $handler): RedirectResponse
    {
        return self::act(
            $request,
            fn () => $handler->handle(new RemoveAssignment($coupon, $customer)),
            'promotions::admin_coupons.toast.removed',
        );
    }

    /**
     * The coupon's own store, as the use case will read it: its currency writes the amounts typed.
     *
     * @throws DomainError
     */
    private static function storeOf(string $coupon, ViewCouponHandler $view, PlatformApi $platform): StoreDto
    {
        $storeId = $view->handle(new ViewCoupon($coupon))->storeId;

        return $platform->store(StoreId::fromString($storeId)) ?? throw new Unauthorized(PromotionsPermissions::COUPON_MANAGE);
    }

    /**
     * Does the work and answers with the toast that says it was done, or with the refusal - beside its
     * own box when it names one of the form's (CouponForm::FIELDS).
     *
     * @param  Closure(): mixed  $work
     */
    private static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (CouponFormProblem $problem) {
            return back()->withInput()->withErrors([$problem->field => __("promotions::admin_coupons.problem.{$problem->rule}", $problem->values)]);
        } catch (InvalidPromotionAttribute $error) {
            if (in_array($error->attribute, CouponForm::FIELDS, true) && ! $request->expectsJson()) {
                return back()->withInput()->withErrors([$error->attribute => FormErrors::message($error)]);
            }

            return FormErrors::back($request, $error);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __($done));
    }

    /** The product search of the rule picker, as typed: 2 to 100 characters, else none. */
    private static function search(Request $request): ?string
    {
        $text = self::query($request, 'product_search');
        $length = $text === null ? 0 : mb_strlen($text);

        return $length < 2 || $length > 100 ? null : $text;
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
