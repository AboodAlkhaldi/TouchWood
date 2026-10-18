<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Controller;

use App\Http\AdminArea;
use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPoints;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Query\ListPointHolders\ListPointHoldersHandler;
use Modules\Loyalty\Application\Query\ListRedemptions\ListRedemptionsHandler;
use Modules\Loyalty\Application\Query\LoyaltyActions\LoyaltyActionsForReader;
use Modules\Loyalty\Application\Query\ViewCustomerPoints\ViewCustomerPointsHandler;
use Modules\Loyalty\Presentation\Http\Resource\PointsPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/*
| Loyalty's staff screens (loyalty.md §4.1): Points › Customers (L1), one customer's points (L2) and
| the change made by hand from its dialog (L3), Points › Redemptions (L4), and Points › Settings (L6),
| which is Platform's settings screen opened at the Points card.
|
| Who may open any of these is Loyalty's answer, never the routing's: each read asks for the points
| job in the store shown, and each change by hand for its own, admin-only job (§3).
*/
final readonly class PointsAdminController
{
    /** @var list<string> */
    private const array WORDS = ['loyalty::admin_points', 'loyalty::errors', 'admin'];

    // What asks who is acting - the pages, the handlers - is taken by each action, never kept here: a
    // controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    /** L1. */
    public function customers(Request $request, PointsPages $pages, ListPointHoldersHandler $list): Response
    {
        return $this->page->render('Loyalty/Admin/Points/Customers', $pages->holders(
            self::text($request, 'store'),
            self::search($request),
            self::number($request, 'after_points'),
            self::text($request, 'after_account'),
            $list,
        )->toArray(), self::WORDS);
    }

    /** L2. */
    public function customer(Request $request, string $customer, PointsPages $pages, ViewCustomerPointsHandler $view, LoyaltyActionsForReader $actions): Response
    {
        return $this->page->render('Loyalty/Admin/Points/Customer', $pages->customer(
            $customer,
            self::text($request, 'store'),
            self::text($request, 'before_at'),
            self::number($request, 'before_id'),
            $view,
            $actions,
        )->toArray(), self::WORDS);
    }

    /** L3: Add or Remove, in the store the page shows. */
    public function change(Request $request, string $customer, StoreChoices $choices, AddPointsHandler $add, DeductPointsHandler $deduct): RedirectResponse
    {
        $mode = $request->input('mode');
        $points = $request->input('points');
        $reason = $request->input('reason');

        if (! in_array($mode, ['add', 'remove'], true)) {
            return back()->withErrors(['form' => __('loyalty::admin_points.dialog.mode_missing')]);
        }

        // The store the page shows, always sent: none would mean the first store that is on, which
        // nobody chose (the review of 2026-10-19).
        $storeCode = self::text($request, 'store');

        if ($storeCode === null) {
            return back()->withErrors(['form' => __('loyalty::admin_points.dialog.store_missing')]);
        }

        // A whole number typed as digits, as the box checks it; the handler checks its size.
        $digits = is_int($points) ? (string) $points : (is_string($points) ? trim($points) : '');

        if ($digits === '' || ! ctype_digit($digits) || strlen($digits) > 10) {
            return back()->withInput($request->only(['mode', 'points', 'reason']))->withErrors(['points' => __('loyalty::admin_points.dialog.points_whole')]);
        }

        try {
            $store = $choices->chosen($storeCode, LoyaltyPermissions::VIEW) ?? throw new Unauthorized(LoyaltyPermissions::VIEW);
            $reason = is_string($reason) ? $reason : '';

            if ($mode === 'add') {
                $add->handle(new AddPoints($customer, $store->id, (int) $digits, $reason));
            } else {
                $deduct->handle(new DeductPoints($customer, $store->id, (int) $digits, $reason));
            }
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['mode', 'points', 'reason']);
        }

        // Grouped, in Latin digits, as every number on the screens (amendment 12 of catalog.md).
        return back()->with('status', __(
            $mode === 'add' ? 'loyalty::admin_points.toast.added' : 'loyalty::admin_points.toast.removed',
            ['count' => number_format((int) $digits)],
        ));
    }

    /** L4. */
    public function redemptions(Request $request, PointsPages $pages, ListRedemptionsHandler $list): Response
    {
        return $this->page->render('Loyalty/Admin/Points/Redemptions', $pages->redemptions(
            self::text($request, 'store'),
            self::text($request, 'before_at'),
            self::number($request, 'before_id'),
            $list,
        )->toArray(), self::WORDS);
    }

    /**
     * L6: Platform's settings screen, at the Points card, for the same store. A full page load, not
     * Inertia's own visit: a visit's redirect drops the address's "#", so the screen would open at
     * its top instead of at the card (the review of 2026-10-19).
     */
    public function settings(Request $request): SymfonyResponse
    {
        $store = self::text($request, 'store');

        return Inertia::location('/'.AdminArea::PREFIX.'/settings'.($store === null ? '' : '?store='.rawurlencode($store)).'#settings-loyalty');
    }

    /**
     * The search as asked in the address: 3 to 100 characters searches; anything shorter or longer
     * searches nothing (§4.1 L1), as the box itself never sends it, rather than refusing the page.
     */
    private static function search(Request $request): ?string
    {
        $text = self::text($request, 'search');
        $length = $text === null ? 0 : mb_strlen($text);

        return $length < 3 || $length > 100 ? null : $text;
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key) ?? $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A whole number from the address, or none; anything else is no number, not a 500. */
    private static function number(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 ? (int) $value : null;
    }
}
