<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Application\Command\ChangeGiftLevel\ChangeGiftLevel;
use Modules\Promotions\Application\Command\ChangeGiftLevel\ChangeGiftLevelHandler;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevel;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevelHandler;
use Modules\Promotions\Application\Command\GiftLevelFields;
use Modules\Promotions\Application\Command\SwitchGiftLevel\SwitchGiftLevel;
use Modules\Promotions\Application\Command\SwitchGiftLevel\SwitchGiftLevelHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\GiftLevelRow;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevels;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevelsHandler;
use Modules\Promotions\Application\Query\SearchGiftSizes\SearchGiftSizesHandler;
use Modules\Promotions\Domain\Exception\GiftLevelClash;
use Modules\Promotions\Domain\Exception\GiftLevelNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Presentation\Http\Resource\GiftLevelPages;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/*
| Promotions' gift levels screen (promotions.md §4.1 M4): the store's levels, New Level… and Edit… in
| one dialog, and Turn Off / Turn On per row. No delete: a level is switched off (§3).
|
| Who may open or change any of these is Promotions' answer, never the routing's: the list asks for a
| Marketing job in the store, each change for `promotions.gift.manage` (§3).
*/
final readonly class GiftLevelsController
{
    /** @var list<string> */
    private const array WORDS = ['promotions::admin_gifts', 'promotions::errors', 'admin'];

    /** The dialog's own boxes, where a refusal naming one is said. */
    private const array FIELDS = ['amount', 'gift_variant', 'buyers', 'dates', 'starts_at', 'ends_at'];

    public function __construct(
        private Page $page,
    ) {}

    /** M4. */
    public function index(Request $request, GiftLevelPages $pages, ListGiftLevelsHandler $levels, SearchGiftSizesHandler $sizes): Response
    {
        $search = self::query($request, 'search');
        $length = $search === null ? 0 : mb_strlen($search);

        return $this->page->render('Promotions/Admin/GiftLevels/Index', $pages->list(
            self::query($request, 'store'),
            // As the box sends it: 2 to 100 characters; anything else searches nothing.
            $length >= 2 && $length <= 100 ? $search : null,
            $levels,
            $sizes,
        )->toArray(), self::WORDS);
    }

    /** New Level…, in the store the dialog names. */
    public function store(Request $request, StoreChoices $choices, CreateGiftLevelHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $choices, $handler): void {
            $store = self::storeOf($request, $choices);
            $handler->handle(new CreateGiftLevel($store->id, self::fields($request, $store)));
        }, 'promotions::admin_gifts.toast.created');
    }

    /**
     * Edit…: the same boxes. The amount is read in the level's own store: the store the dialog names must
     * be the level's (found among its levels), else the level is not found there.
     */
    public function update(Request $request, string $level, StoreChoices $choices, ListGiftLevelsHandler $levels, ChangeGiftLevelHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $level, $choices, $levels, $handler): void {
            $store = self::storeOf($request, $choices);
            $ours = array_filter($levels->handle(new ListGiftLevels($store->id)), static fn (GiftLevelRow $row): bool => $row->id === strtolower($level));

            if ($ours === []) {
                throw new GiftLevelNotFound;
            }

            $handler->handle(new ChangeGiftLevel($level, self::fields($request, $store)));
        }, 'promotions::admin_gifts.toast.saved');
    }

    /** Turn Off / Turn On, saved at once. */
    public function switch(Request $request, string $level, SwitchGiftLevelHandler $handler): RedirectResponse
    {
        $on = $request->boolean('active');

        return self::act(
            $request,
            fn () => $handler->handle(new SwitchGiftLevel($level, $on)),
            $on ? 'promotions::admin_gifts.toast.on' : 'promotions::admin_gifts.toast.off',
        );
    }

    /**
     * The store the dialog names, where the reader may manage gift levels; its currency reads the amount.
     *
     * @throws Unauthorized
     */
    private static function storeOf(Request $request, StoreChoices $choices): StoreDto
    {
        return $choices->chosen(CouponForm::text($request, 'store'), PromotionsPermissions::GIFT_MANAGE) ?? throw new Unauthorized(PromotionsPermissions::GIFT_MANAGE);
    }

    /**
     * The dialog as posted: the amount as the store writes it, the size picked, who it is for, its dates.
     *
     * @throws CouponFormProblem
     */
    private static function fields(Request $request, StoreDto $store): GiftLevelFields
    {
        return new GiftLevelFields(
            CouponForm::money($request, 'amount', $store->currencyCode, $store->currencyExponent) ?? 0,
            strtolower(CouponForm::text($request, 'gift_variant') ?? ''),
            $request->boolean('for_public'),
            $request->boolean('for_company'),
            CouponForm::moment($request, 'starts_at'),
            CouponForm::moment($request, 'ends_at'),
            // The stock tick box (Q11 (c)): also limit the size's normal sales to its stock, in the save.
            $request->boolean('make_stock_dependent'),
        );
    }

    /**
     * Does the work and answers with the toast, or with the refusal - beside its own box when it names
     * one of the dialog's; a clash with another level is the dialog's own message (§7).
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
            if (in_array($error->attribute, self::FIELDS, true) && ! $request->expectsJson()) {
                return back()->withInput()->withErrors([$error->attribute => FormErrors::message($error)]);
            }

            return FormErrors::back($request, $error);
        } catch (GiftLevelClash $clash) {
            return back()->withInput()->withErrors(['clash' => FormErrors::message($clash)]);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __($done));
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
