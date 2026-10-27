<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\BuyAgain\BuyAgain;
use Modules\Sales\Application\Command\BuyAgain\BuyAgainHandler;
use Modules\Sales\Application\Command\CloseReturn\CloseReturn;
use Modules\Sales\Application\Command\CloseReturn\CloseReturnHandler;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturn;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturnHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturn;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturnHandler;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturn;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturnHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturn;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturnHandler;
use Modules\Sales\Application\Query\ListReturns\ListReturns;
use Modules\Sales\Application\Query\ListReturns\ListReturnsHandler;
use Modules\Sales\Application\Query\PreviewReturnRefund\PreviewReturnRefund;
use Modules\Sales\Application\Query\PreviewReturnRefund\PreviewReturnRefundHandler;
use Modules\Sales\Application\Query\SearchEditSizes\SearchEditSizes;
use Modules\Sales\Application\Query\SearchEditSizes\SearchEditSizesHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Public\Enums\CartLineProblem;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| What the Sales screens asked besides the lists (sales.md §3.1, §3.2; the screens' B6, B7, B10, B11): Buy
| Again from an own order; the order edit's size search with today's prices and stock; what completing a
| received return would refund; the returns' "Closed" tab.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** @return array{order: string, number: string, customer: string, address: string, variants: list<string>, product: string} */
function smOrder(): array
{
    $ready = Cx::ready();
    $placed = Cx::place(Cx::start($ready['address'])->id);

    return [...$ready, 'order' => $placed->id, 'number' => $placed->number];
}

/** @return array<string, int> variant id|mode => quantity */
function smCart(): array
{
    $lines = [];

    foreach (DB::table('sales.cart_lines')->get(['variant_id', 'sale_mode', 'quantity']) as $line) {
        $lines[$line->variant_id.'|'.$line->sale_mode] = (int) $line->quantity;
    }

    return $lines;
}

/**
 * The order delivered, its return of the first line's pieces asked and accepted, acting as staff who manage returns.
 *
 * @param  array{order: string, customer: string}  $sold
 */
function smReturn(array $sold, int $pieces = 1, bool $accept = true): string
{
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::RETURN_MANAGE], ['sa']));
    if (DB::table('sales.orders')->where('id', $sold['order'])->value('status') !== 'COMPLETED') {
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($sold['order']));
        DB::table('sales.orders')->where('id', $sold['order'])->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($sold['order'], 'SMSA', null));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($sold['order']));
    }
    $line = (string) DB::table('sales.order_lines')->where('order_id', $sold['order'])->where('is_gift', false)->orderBy('unit_minor', 'desc')->value('id');
    Fx::actAsCustomer($sold['customer']);
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($sold['order'], 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => $pieces]]));
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE, SalesPermissions::ORDER_VIEW], ['sa']));

    if ($accept) {
        app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));
    }

    return $return;
}

describe('buy again', function () {
    it('puts an own order\'s lines back in the cart, added to what it holds, the gift left out', function () {
        ['number' => $number, 'variants' => $variants, 'customer' => $customer] = smOrder();
        Cx::add($variants[1], 3);

        $result = app(BuyAgainHandler::class)->handle(new BuyAgain(strtolower($number)));

        expect([$result->added, $result->skipped, $result->storeId])->toBe([2, [], Fx::storeId('sa')])
            ->and(smCart())->toEqual([$variants[0].'|RETAIL' => 2, $variants[1].'|RETAIL' => 4])
            ->and(DB::table('sales.carts')->where('customer_id', $customer)->count())->toBe(1);
    });

    it('leaves out a size not sold now and one not for the account, naming why, and adds the rest as they were', function () {
        ['number' => $number, 'variants' => $variants, 'product' => $product] = smOrder();
        DB::table('catalog.store_variants')->where('variant_id', $variants[0])->update(['not_available_now' => true]);
        // The minimum since raised above what was bought: added as it was, the cart marks it.
        DB::table('catalog.store_products')->where('product_id', $product)->where('store_id', Fx::storeId('sa'))->update(['retail_minimum' => 5]);

        $result = app(BuyAgainHandler::class)->handle(new BuyAgain($number));

        expect($result->added)->toBe(1)
            ->and(array_map(fn ($skipped): array => [$skipped->variantId, $skipped->quantity, $skipped->reason], $result->skipped))->toBe([[$variants[0], 2, CartLineProblem::NotAvailable]])
            ->and($result->skipped[0]->nameEn)->toBe((string) DB::table('catalog.products')->where('id', $product)->value('name_en'))
            ->and(smCart())->toEqual([$variants[1].'|RETAIL' => 1]);

        $hidden = Px::category('Trade only');
        DB::table('catalog.products')->where('id', $product)->update(['category_id' => $hidden]);
        DB::table('catalog.store_category_audiences')->insert(['store_id' => Fx::storeId('sa'), 'category_id' => $hidden, 'for_individuals' => false, 'for_companies' => true, 'created_at' => now(), 'updated_at' => now()]);

        expect(array_column(app(BuyAgainHandler::class)->handle(new BuyAgain($number))->skipped, 'reason'))->toBe([CartLineProblem::NotAvailable, CartLineProblem::NotForYourAccount]);
    });

    it('leaves out a wholesale line for an individual, and every line of a store switched off since', function () {
        ['number' => $number, 'order' => $order, 'variants' => $variants] = smOrder();
        DB::table('sales.order_lines')->where('order_id', $order)->where('variant_id', $variants[1])->update(['sale_mode' => 'WHOLESALE']);
        DB::table('catalog.store_variants')->where('variant_id', $variants[1])->update(['sells_wholesale' => true]);

        $wholesale = app(BuyAgainHandler::class)->handle(new BuyAgain($number));

        expect(array_map(fn ($skipped): array => [$skipped->variantId, $skipped->reason], $wholesale->skipped))->toBe([[$variants[1], CartLineProblem::NotForYourAccount]]);

        // A store switched off since (the base store never is): nothing goes back.
        $ready = Cx::ready(storeCode: 'eg');
        $egypt = Cx::place(Cx::start($ready['address'], storeCode: 'eg')->id, 'eg')->number;
        DB::table('sales.cart_lines')->delete();
        $customer = $ready['customer'];
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsCustomer($customer);
        $off = app(BuyAgainHandler::class)->handle(new BuyAgain($egypt));

        expect([$off->added, array_values(array_unique(array_map(fn ($skipped): string => $skipped->reason->value, $off->skipped)))])->toBe([0, ['NOT_AVAILABLE']])
            ->and(DB::table('sales.cart_lines')->count())->toBe(0);
    });

    it('answers another customer\'s order as none, and staff as not allowed', function () {
        ['number' => $number] = smOrder();
        Fx::actAsCustomer(Cx::customer());

        expect(fn () => app(BuyAgainHandler::class)->handle(new BuyAgain($number)))->toThrow(OrderNotFound::class)
            ->and(fn () => app(BuyAgainHandler::class)->handle(new BuyAgain('nothing')))->toThrow(OrderNotFound::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(BuyAgainHandler::class)->handle(new BuyAgain($number)))->toThrow(Unauthorized::class);
    });
});

describe('the order edit\'s size search', function () {
    it('finds the store\'s sizes the order has not, with today\'s prices, the wholesale bands and the stock', function () {
        ['order' => $order, 'variants' => $variants] = smOrder();
        ['product' => $other, 'variants' => [$third]] = Px::ready(['90 cm']);
        Rx::switchOn('sa', [$third]);
        Rx::set('sa', [$third => '30.00']);
        Ix::count('sa', [$third => 7]);
        DB::table('catalog.store_variants')->where('variant_id', $third)->update(['sells_wholesale' => true]);
        DB::table('catalog.store_products')->where('product_id', $other)->where('store_id', Fx::storeId('sa'))->update(['wholesale_minimum' => 5]);
        Fx::actAsStaff(Fx::staffWith([PricingPermissions::WHOLESALE_EDIT], ['sa']));
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Fx::storeId('sa'), $third, [5 => '25.00', 10 => '20.00']));
        // A company's order sees wholesale prices; an individual's would not.
        DB::table('sales.orders')->where('id', $order)->update(['audience' => 'COMPANY']);
        // Not available now: the edit would refuse it, so it is not offered.
        ['variants' => [$unavailable]] = Px::ready(['95 cm']);
        Rx::switchOn('sa', [$unavailable]);
        Rx::set('sa', [$unavailable => '40.00']);
        Ix::count('sa', [$unavailable => 7]);
        DB::table('catalog.store_variants')->where('variant_id', $unavailable)->update(['not_available_now' => true]);
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT], ['sa']));

        $rows = app(SearchEditSizesHandler::class)->handle(new SearchEditSizes($order));
        $found = array_values(array_filter($rows, fn ($row): bool => $row->variantId === $third))[0] ?? null;

        expect(array_column($rows, 'variantId'))->not->toContain($unavailable)
            ->and(array_column($rows, 'variantId'))->not->toContain($variants[0])
            ->and(array_column($rows, 'variantId'))->not->toContain($variants[1])
            ->and(($found->retail ?? null)['now']->minorUnits ?? null)->toBe(30_00)
            ->and(($found->retail ?? null)['before'] ?? null)->toBeNull()
            ->and([($found->wholesale ?? null)['minimum'] ?? null, ($found->wholesale ?? null)['now']->minorUnits ?? null])->toBe([5, 25_00])
            ->and(array_map(fn ($band): array => [$band->fromQuantity, $band->unit->minorUnits], $found->wholesale['bands'] ?? []))->toBe([[5, 25_00], [10, 20_00]])
            ->and([$found?->available, $found?->exponent])->toBe([7, 2])
            ->and(array_column(app(SearchEditSizesHandler::class)->handle(new SearchEditSizes($order, (string) DB::table('catalog.variants')->where('id', $third)->value('code'))), 'variantId'))->toBe([$third]);

        DB::table('sales.orders')->where('id', $order)->update(['audience' => 'PUBLIC']);
        $individual = array_values(array_filter(app(SearchEditSizesHandler::class)->handle(new SearchEditSizes($order)), fn ($row): bool => $row->variantId === $third))[0] ?? null;
        expect($individual?->wholesale)->toBeNull()
            ->and(($individual->retail ?? null)['now']->minorUnits ?? null)->toBe(30_00);
    });

    it('answers an order of a store the reader does not edit as none', function () {
        ['order' => $order] = smOrder();
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT], ['eg']));

        expect(fn () => app(SearchEditSizesHandler::class)->handle(new SearchEditSizes($order)))->toThrow(OrderNotFound::class);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(fn () => app(SearchEditSizesHandler::class)->handle(new SearchEditSizes($order)))->toThrow(Unauthorized::class);
    });
});

describe('returns', function () {
    it('shows what completing a received return refunds, as completing it does, the shipping with the last pieces', function (int $pieces, bool $withShipping) {
        Cx::setting(SalesSettings::FLAT_FEE, 25_00);
        $sold = smOrder();
        // One size only, of two pieces: a return of both makes every bought piece returned.
        DB::table('sales.order_lines')->where('order_id', $sold['order'])->where('variant_id', $sold['variants'][1])->delete();
        $return = smReturn($sold, $pieces);
        $line = (string) DB::table('sales.return_lines')->where('return_id', $return)->value('order_line_id');
        app(ReceiveReturnHandler::class)->handle(new ReceiveReturn($return, [['orderLineId' => $line, 'received' => $pieces, 'restocked' => $pieces]]));

        $preview = app(PreviewReturnRefundHandler::class)->handle(new PreviewReturnRefund($return));
        $refunded = app(CompleteReturnHandler::class)->handle(new CompleteReturn($return));

        $shippingVat = (int) DB::table('sales.orders')->where('id', $sold['order'])->value('vat_minor')
            - (int) DB::table('sales.order_lines')->where('order_id', $sold['order'])->sum('vat_share_minor');

        expect($preview->total->minorUnits)->toBe($refunded)
            ->and($preview->goods->minorUnits + $preview->vat->minorUnits + $preview->shipping->minorUnits + $preview->shippingVat->minorUnits)->toBe($refunded)
            ->and([$preview->shipping->minorUnits, $preview->shippingVat->minorUnits])->toBe($withShipping ? [25_00, $shippingVat] : [0, 0])
            ->and($preview->vat->minorUnits)->toBeGreaterThan(0)
            ->and($preview->exponent)->toBe(2);
    })->with([
        'one of two pieces' => [1, false],
        'both pieces, the last' => [2, true],
    ]);

    it('previews only a received return, for staff who manage returns', function () {
        $return = smReturn(smOrder());

        expect(fn () => app(PreviewReturnRefundHandler::class)->handle(new PreviewReturnRefund($return)))->toThrow(InvalidOrderChange::class);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(fn () => app(PreviewReturnRefundHandler::class)->handle(new PreviewReturnRefund($return)))->toThrow(Unauthorized::class);
    });

    it('lists refused, withdrawn and closed returns together as the Closed tab', function () {
        $sold = smOrder();
        $refused = smReturn($sold, accept: false);
        app(RefuseReturnHandler::class)->handle(new RefuseReturn($refused, 'Used beyond the trial'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $accepted = smReturn($sold);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        Fx::actAsCustomer($sold['customer']);
        $line = (string) DB::table('sales.order_lines')->where('order_id', $sold['order'])->where('is_gift', false)->orderBy('unit_minor')->value('id');
        $withdrawn = app(RequestReturnHandler::class)->handle(new RequestReturn($sold['order'], 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
        app(WithdrawReturnHandler::class)->handle(new WithdrawReturn($withdrawn));
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, SalesPermissions::RETURN_MANAGE], ['sa']));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        // Accepted, its pieces never came: closed.
        $closed = smReturn($sold);
        app(CloseReturnHandler::class)->handle(new CloseReturn($closed));

        expect(array_column(app(ListReturnsHandler::class)->handle(new ListReturns(status: 'closed_all'))->returns, 'returnId'))->toBe([$closed, $withdrawn, $refused])
            ->and(array_column(app(ListReturnsHandler::class)->handle(new ListReturns(status: 'ACCEPTED'))->returns, 'returnId'))->toBe([$accepted]);
    });
});
