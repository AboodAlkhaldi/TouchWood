<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\EditOrder\EditOrder;
use Modules\Sales\Application\Command\EditOrder\EditOrderHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Order\EditPlan;
use Modules\Sales\Application\Query\PreviewOrderEdit\PreviewOrderEdit;
use Modules\Sales\Application\Query\PreviewOrderEdit\PreviewOrderEditHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotEditable;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Staff edits before shipping (sales.md §1.7): the order's lines as they should be — kept parts at their
| sold prices, a raised quantity a new part at today's, a lowered one off the newest part — checked as
| checkout; the order's own rules: the coupon only stays or shrinks, the points give way first, its shipping;
| the gift staff's choice; the address another of the customer's; every share again; Promotions, Loyalty,
| then Inventory's hold last, all or nothing; waiting or approved, unshipped, nothing ticked.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * An order placed — two of the first size at 100.00, one of the second at 50.00 — and staff who edit it.
 *
 * @return array{order: string, variants: list<string>, customer: string, product: string}
 */
function edited(bool $withCoupon = false, bool $withPoints = false, bool $withGift = false, int $ceiling = 100): array
{
    $ready = Cx::ready();

    if ($withCoupon || $withPoints) {
        Pr::ceiling($ceiling);
    }

    if ($withCoupon) {
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
    }

    if ($withPoints) {
        Lx::programme();
        Lx::deliver($ready['customer'], 1_000_00);
    }

    if ($withGift) {
        Pr::giftLevel($ready['variants'][1], 100_00);
    }

    $order = Cx::place(Cx::start($ready['address'], usePoints: $withPoints)->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT, SalesPermissions::ORDER_APPROVE], ['sa']));

    return ['order' => $order, 'variants' => $ready['variants'], 'customer' => $ready['customer'], 'product' => $ready['product']];
}

/**
 * @param  list<array{0: string, 1: int, 2?: string}>  $lines  [size, quantity, mode]
 */
function editTo(string $order, array $lines, ?string $address = null, string $gift = 'KEEP'): string
{
    return app(EditOrderHandler::class)->handle(new EditOrder($order, 'Called the customer', editLines($lines), $address, $gift));
}

/**
 * @param  list<array{0: string, 1: int, 2?: string}>  $lines
 */
function editPreview(string $order, array $lines, string $gift = 'KEEP'): EditPlan
{
    return app(PreviewOrderEditHandler::class)->handle(new PreviewOrderEdit($order, editLines($lines), null, $gift));
}

/**
 * @param  list<array{0: string, 1: int, 2?: string}>  $lines
 * @return list<array{variantId: string, mode: string, quantity: int}>
 */
function editLines(array $lines): array
{
    return array_map(static fn (array $line): array => ['variantId' => $line[0], 'mode' => $line[2] ?? 'RETAIL', 'quantity' => $line[1]], $lines);
}

/** @return list<array{string, int, int, bool, ?string}> size, quantity, unit, gift, the edit that added it */
function editedLines(string $order): array
{
    return array_values(DB::table('sales.order_lines')->where('order_id', $order)->orderBy('is_gift')->orderBy('variant_id')->orderBy('unit_minor')->get()
        ->map(fn ($line): array => [(string) $line->variant_id, (int) $line->quantity, (int) $line->unit_minor, (bool) $line->is_gift, $line->edit_id === null ? null : (string) $line->edit_id])->all());
}

function editedRow(string $order): ?stdClass
{
    return DB::table('sales.orders')->where('id', $order)->first();
}

describe('the lines', function () {
    it('adds a part at today price to a raised line, keeps the sold one, and holds the new pieces', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        Rx::set('sa', [$variants[0] => '120.00']);

        $edit = editTo($order, [[$variants[0], 3], [$variants[1], 1]]);

        $lines = editedLines($order);
        expect(array_map(fn ($line): array => [$line[0], $line[1], $line[2], $line[4] === null ? null : 'edit'], $lines))->toEqualCanonicalizing([
            [$variants[0], 2, 100_00, null], [$variants[0], 1, 120_00, 'edit'], [$variants[1], 1, 50_00, null],
        ])
            ->and(DB::table('sales.order_lines')->where('order_id', $order)->where('quantity', 1)->where('variant_id', $variants[0])->value('edit_id'))->toBe($edit)
            ->and((int) editedRow($order)?->gross_subtotal_minor)->toBe(370_00)
            ->and((int) editedRow($order)?->due_minor)->toBe((int) editedRow($order)?->order_total_minor)
            ->and((int) Ix::stock('sa', $variants[0])?->held)->toBe(3)
            ->and((int) DB::table('sales.order_lines')->where('order_id', $order)->sum('vat_share_minor'))->toBe((int) editedRow($order)?->vat_minor)
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'EDITED')->value('edit_id'))->toBe($edit)
            ->and(DB::table('sales.order_edits')->where('id', $edit)->value('reason'))->toBe('Called the customer')
            ->and(Fx::audits('sales.order.edited', $order))->toBe(1);
    });

    it('takes a lowered quantity off the newest part first, and a line not named goes', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        Rx::set('sa', [$variants[0] => '120.00']);
        editTo($order, [[$variants[0], 3], [$variants[1], 1]]);

        editTo($order, [[$variants[0], 2]]);

        expect(array_map(fn ($line): array => [$line[0], $line[1], $line[2], $line[4]], editedLines($order)))->toBe([[$variants[0], 2, 100_00, null]])
            ->and((int) Ix::stock('sa', $variants[1])?->held)->toBe(0)
            ->and((int) editedRow($order)?->gross_subtotal_minor)->toBe(200_00);
    });

    it('adds a size the order did not have, checked as checkout', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        ['variants' => [$third]] = Px::ready(['90 cm']);
        Rx::switchOn('sa', [$third]);
        Rx::set('sa', [$third => '30.00']);
        Ix::count('sa', [$third => 10]);

        editTo($order, [[$variants[0], 2], [$variants[1], 1], [$third, 4]]);

        expect((int) editedRow($order)?->gross_subtotal_minor)->toBe(370_00)
            ->and((int) Ix::stock('sa', $third)?->held)->toBe(4);
    });

    it('refuses what checkout would', function (Closure $lines, string $error) {
        ['order' => $order, 'variants' => $variants] = edited();

        expect(fn () => editTo($order, $lines($variants)))->toThrow($error)
            ->and(DB::table('sales.order_edits')->count())->toBe(0);
    })->with([
        'nothing left' => [fn (array $v) => [], InvalidSalesAttribute::class],
        'a size twice' => [fn (array $v) => [[$v[0], 1], [$v[0], 2]], InvalidSalesAttribute::class],
        'wholesale to an individual' => [fn (array $v) => [[$v[0], 2], [$v[1], 1, 'WHOLESALE']], NotForYourAccount::class],
        'a size not sold here' => [fn (array $v) => [[$v[0], 2], [Px::ready(['10 cm'])['variants'][0], 1]], NotOrderable::class],
        'an added size in a category hidden from the account' => [function (array $v) {
            $hidden = Px::category('Trade only');
            ['variants' => [$closed]] = Px::ready(['90 cm'], $hidden);
            DB::table('catalog.store_category_audiences')->insert(['store_id' => Fx::storeId('sa'), 'category_id' => $hidden, 'for_individuals' => false, 'for_companies' => true, 'created_at' => now(), 'updated_at' => now()]);
            Rx::switchOn('sa', [$closed]);
            Rx::set('sa', [$closed => '30.00']);
            Ix::count('sa', [$closed => 10]);

            return [[$v[0], 2], [$v[1], 1], [$closed, 1]];
        }, NotForYourAccount::class],
        'an added size with no price' => [function (array $v) {
            ['variants' => [$unpriced]] = Px::ready(['90 cm']);
            Rx::switchOn('sa', [$unpriced]);
            Ix::count('sa', [$unpriced => 10]);

            return [[$v[0], 2], [$v[1], 1], [$unpriced, 1]];
        }, NotOrderable::class],
    ]);

    it('checks a lowered line only against its limits, a raised one against all of checkout', function () {
        ['order' => $order, 'variants' => $variants, 'product' => $product] = edited();
        $listing = fn (array $changes) => DB::table('catalog.store_products')->where('product_id', $product)->where('store_id', Fx::storeId('sa'))->update($changes);
        $listing(['not_available_now' => true]);

        // Not available now: lowered, kept as is, or removed — never raised.
        expect(Pr::thrown(fn () => editTo($order, [[$variants[0], 3], [$variants[1], 1]]), NotOrderable::class)->variantIds)->toBe([$variants[0]]);
        editTo($order, [[$variants[0], 1], [$variants[1], 1]]);
        expect((int) editedRow($order)?->gross_subtotal_minor)->toBe(150_00);

        // Within its limits only: a minimum of 2 and a most of 3 — the second size's 1, unchanged, not checked.
        $listing(['not_available_now' => false, 'retail_minimum' => 2, 'retail_maximum' => 3]);
        editTo($order, [[$variants[0], 3], [$variants[1], 1]]);
        // Lowered, yet still above a most of 1.
        $listing(['retail_minimum' => 1, 'retail_maximum' => 1]);
        expect(Pr::thrown(fn () => editTo($order, [[$variants[0], 2], [$variants[1], 1]]), NotOrderable::class)->variantIds)->toBe([$variants[0]]);
        $listing(['retail_minimum' => 2, 'retail_maximum' => 3]);
        editTo($order, [[$variants[0], 2], [$variants[1], 1]]);

        expect(Pr::thrown(fn () => editTo($order, [[$variants[0], 1], [$variants[1], 1]]), NotOrderable::class)->variantIds)->toBe([$variants[0]])
            ->and(Pr::thrown(fn () => editTo($order, [[$variants[0], 4], [$variants[1], 1]]), NotOrderable::class)->variantIds)->toBe([$variants[0]])
            ->and((int) editedRow($order)?->gross_subtotal_minor)->toBe(250_00);
    });

    it('counts a wired order lines on stock as its hold does, kept parts too, by the size switch today', function () {
        $ready = Cx::ready();
        Ix::wired();
        // Switched off by staff (on by default, inventory.md §9.2 #24), then one back on before the edit.
        Ix::switchOff('sa', $ready['variants']);
        $order = Cx::place(Cx::start($ready['address'])->id)->id;
        $variants = $ready['variants'];
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT], ['sa']));
        $counts = fn (string $size): array => array_map(fn ($flag): bool => (bool) $flag, DB::table('sales.order_lines')->where('order_id', $order)->where('variant_id', $size)->pluck('counts_on_stock')->all());
        expect($counts($variants[0]))->toBe([false]);

        app(InventoryApi::class)->makeStockDependent(StoreId::fromString(Fx::storeId('sa')), [$variants[0]]);
        editTo($order, [[$variants[0], 3], [$variants[1], 1]]);

        expect($counts($variants[0]))->toBe([true, true])
            ->and($counts($variants[1]))->toBe([false])
            ->and((int) Ix::stock('sa', $variants[0])?->held)->toBe(3);
    });

    it('holds every piece or none: an edit Inventory cannot hold changes nothing', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        $before = editedLines($order);

        expect(fn () => editTo($order, [[$variants[0], 500], [$variants[1], 1]]))->toThrow(DomainError::class)
            ->and(editedLines($order))->toBe($before)
            ->and(DB::table('sales.order_edits')->count())->toBe(0);
    });
});

describe('the order own rules', function () {
    it('lets the coupon only shrink, recording its new discount', function () {
        ['order' => $order, 'variants' => $variants] = edited(withCoupon: true);
        expect((int) editedRow($order)?->coupon_discount_minor)->toBe(37_50);

        $edit = editTo($order, [[$variants[0], 2]]);

        expect((int) editedRow($order)?->coupon_discount_minor)->toBe(30_00)
            ->and((int) DB::table('promotions.coupon_uses')->where('order_id', $order)->value('discount_minor'))->toBe(30_00)
            ->and(DB::table('promotions.coupon_uses')->where('order_id', $order)->value('last_edit_id'))->toBe($edit)
            ->and((int) DB::table('sales.order_lines')->where('order_id', $order)->sum('coupon_share_minor'))->toBe(30_00);
    });

    it('lets the points give way before the coupon, under the ceiling the order was placed under', function () {
        // A 20% ceiling: 50.00 of the 250.00 placed — the coupon's 37.50 and the points' 10.00 fit.
        ['order' => $order, 'variants' => $variants] = edited(withCoupon: true, withPoints: true, ceiling: 20);
        $before = (int) editedRow($order)?->points_discount_minor;
        Pr::ceiling(50);

        // 50.00 left: a room of 10.00 by the order's own 20%; the coupon keeps its 7.50, the points get 2.50.
        editTo($order, [[$variants[1], 1]]);

        $row = editedRow($order);
        expect($before)->toBe(10_00)
            ->and((int) $row?->coupon_discount_minor)->toBe(7_50)
            ->and((int) $row?->points_discount_minor)->toBe(2_50)
            ->and((int) DB::table('sales.order_lines')->where('order_id', $order)->sum('points_share_minor'))->toBe((int) $row?->points_discount_minor)
            // Loyalty settled the same edit: 7.50 of the points' discount given back.
            ->and((int) DB::table('loyalty.order_returns')->where('order_id', $order)->value('returned_discount_minor'))->toBe(7_50);
    });

    it('keeps the coupon and points as they are when every line stays (the address only)', function () {
        ['order' => $order, 'variants' => $variants, 'customer' => $customer] = edited(withCoupon: true, withPoints: true, ceiling: 20);
        $before = editedRow($order);
        $shares = DB::table('sales.order_lines')->where('order_id', $order)->orderBy('id')->pluck('coupon_share_minor')->all();
        // The coupon now 10% and the ceiling 5%: an edit of the lines would trim both.
        DB::table('promotions.coupons')->update(['percent' => 10]);
        Pr::ceiling(5);

        editTo($order, [[$variants[0], 2], [$variants[1], 1]], Bx::savedAddress($customer, 'sa', 'Home', 'Olaya Street'));

        $row = editedRow($order);
        expect([(int) $row?->coupon_discount_minor, (int) $row?->points_used, (int) $row?->points_discount_minor, (int) $row?->order_total_minor])
            ->toBe([(int) $before?->coupon_discount_minor, (int) $before?->points_used, (int) $before?->points_discount_minor, (int) $before?->order_total_minor])
            ->and(DB::table('sales.order_lines')->where('order_id', $order)->orderBy('id')->pluck('coupon_share_minor')->all())->toBe($shares)
            ->and((string) $row?->address)->toContain('Olaya Street');
    });

    it('ships free above the amount the order was placed under', function () {
        Cx::serve('sa', 25_00);
        Cx::carrierFreeAbove(200_00);
        ['order' => $order, 'variants' => $variants] = edited();
        Cx::carrierFreeAbove(null);

        editTo($order, [[$variants[0], 2]]);
        expect((int) editedRow($order)?->shipping_fee_minor)->toBe(0);

        editTo($order, [[$variants[0], 1]]);
        expect((int) editedRow($order)?->shipping_fee_minor)->toBe(25_00);
    });

    it('keeps the shipping rule the order was placed under', function () {
        Cx::serve('sa', 25_00);
        ['order' => $order, 'variants' => $variants] = edited();
        Cx::serve('sa', 99_00);

        editTo($order, [[$variants[0], 1]]);

        expect((int) editedRow($order)?->shipping_fee_minor)->toBe(25_00);
    });

    it('changes the address to another of the customer own in the store', function () {
        ['order' => $order, 'customer' => $customer] = edited();
        $other = Bx::savedAddress($customer, 'sa', 'Home', 'Olaya Street');
        $lines = [];

        foreach (DB::table('sales.order_lines')->where('order_id', $order)->get() as $line) {
            $lines[] = [(string) $line->variant_id, (int) $line->quantity];
        }

        editTo($order, $lines, $other);

        expect((string) editedRow($order)?->address)->toContain('Olaya Street');
    });

    it('works an edit out without writing it', function () {
        ['order' => $order, 'variants' => $variants] = edited();

        $plan = editPreview($order, [[$variants[0], 1]]);

        expect($plan->totals->grossSubtotal->minorUnits)->toBe(100_00)
            ->and($plan->difference->minorUnits)->toBe(-$plan->totals->orderTotal->minorUnits)
            ->and((int) editedRow($order)?->gross_subtotal_minor)->toBe(250_00)
            ->and(DB::table('sales.order_edits')->count())->toBe(0);
    });

    it('records the money a paid order now holds above its total', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        $paid = (int) editedRow($order)?->paid_minor;

        $plan = editPreview($order, [[$variants[1], 1]]);
        editTo($order, [[$variants[1], 1]]);

        expect($plan->difference->minorUnits)->toBe($paid - $plan->totals->orderTotal->minorUnits)
            ->and($plan->difference->isPositive())->toBeTrue()
            ->and((int) editedRow($order)?->due_minor)->toBeLessThan($paid);
    });
});

describe('the gift', function () {
    it('keeps, removes or swaps the gift as staff choose', function () {
        ['order' => $order, 'variants' => $variants] = edited(withGift: true);
        $kept = editedLines($order);

        editTo($order, [[$variants[0], 2], [$variants[1], 1]]);
        expect(array_values(array_filter(editedLines($order), fn ($line) => $line[3])))->toBe(array_values(array_filter($kept, fn ($line) => $line[3])));

        editTo($order, [[$variants[0], 2], [$variants[1], 1]], gift: 'REMOVE');
        expect(array_filter(editedLines($order), fn ($line) => $line[3]))->toBe([])
            ->and(editedRow($order)?->gift_level_id)->toBeNull();

        $level = (string) DB::table('promotions.gift_levels')->value('id');
        editTo($order, [[$variants[0], 2], [$variants[1], 1]], gift: $level);
        expect(editedRow($order)?->gift_level_id)->toBe($level)
            ->and(DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', true)->value('variant_id'))->toBe($variants[1]);
    });

    it('keeps no gift when the order had none, and reads keep and remove in any case', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        Pr::giftLevel($variants[1], 100_00);

        editTo($order, [[$variants[0], 2], [$variants[1], 1]], gift: 'keep');
        expect(array_filter(editedLines($order), fn ($line) => $line[3]))->toBe([])
            ->and(editedRow($order)?->gift_level_id)->toBeNull();

        editTo($order, [[$variants[0], 2], [$variants[1], 1]], gift: ' remove ');
        expect(array_filter(editedLines($order), fn ($line) => $line[3]))->toBe([]);
    });

    it('offers a gift of a size the order buys when one more is free beyond what the order holds', function (int $inStock, bool $offered) {
        ['order' => $order, 'variants' => $variants] = edited();
        // The order holds its one of the second size; what else there is, is free.
        Ix::count('sa', [$variants[1] => $inStock]);
        $level = Pr::giftLevel($variants[1], 100_00);

        $options = array_map(fn ($option): string => $option->levelId, editPreview($order, [[$variants[0], 2], [$variants[1], 1]])->giftOptions);
        expect($options)->toBe($offered ? [$level] : []);

        if ($offered) {
            editTo($order, [[$variants[0], 2], [$variants[1], 1]], gift: $level);
            expect((int) Ix::stock('sa', $variants[1])?->held)->toBe(2);
        }
    })->with([
        'one free' => [2, true],
        'none free' => [1, false],
    ]);

    it('refuses a level the edited order does not reach', function () {
        ['order' => $order, 'variants' => $variants] = edited(withGift: true);
        $level = (string) DB::table('promotions.gift_levels')->value('id');

        expect(Pr::thrown(fn () => editTo($order, [[$variants[1], 1]], gift: $level), InvalidSalesAttribute::class)->attribute)->toBe('gift');
    });
});

describe('what may be edited', function () {
    it('refuses an order shipped, ticked, cancelled or rejected', function (Closure $state) {
        ['order' => $order, 'variants' => $variants] = edited();
        $state($order, $variants);

        expect(fn () => editTo($order, [[$variants[0], 1]]))->toThrow(NotEditable::class)
            ->and(fn () => editPreview($order, [[$variants[0], 1]]))->toThrow(NotEditable::class);
    })->with([
        'shipped' => [fn (string $order) => DB::table('sales.orders')->where('id', $order)->update([
            'status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA',
        ])],
        'ticked' => [fn (string $order, array $v) => DB::table('sales.order_ticks')->insert(['order_id' => $order, 'store_id' => Fx::storeId('sa'), 'variant_id' => $v[0], 'ticked_by' => Fx::staff(), 'ticked_at' => CarbonImmutable::now()])],
        'cancelled' => [fn (string $order) => DB::table('sales.orders')->where('id', $order)->update(['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'CHANGED_MIND', 'due_minor' => 0])],
        'rejected' => [fn (string $order) => DB::table('sales.orders')->where('id', $order)->update(['status' => 'REJECTED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'decision_reason' => 'No stock', 'due_minor' => 0])],
    ]);

    it('edits an approved order', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));

        editTo($order, [[$variants[0], 1]]);

        expect((int) editedRow($order)?->gross_subtotal_minor)->toBe(100_00);
    });

    it('answers an order of a store the reader does not cover as no order', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT], ['eg']));

        expect(fn () => editTo($order, [[$variants[0], 1]]))->toThrow(OrderNotFound::class);
    });
});

describe('what the module pass added (§8)', function () {
    it('lets a second edit raise the lines back, the coupon held to the first edit\'s trim', function () {
        ['order' => $order, 'variants' => $variants] = edited(withCoupon: true);
        editTo($order, [[$variants[0], 2]]);

        editTo($order, [[$variants[0], 2], [$variants[1], 1]]);

        expect((int) editedRow($order)?->coupon_discount_minor)->toBe(30_00)
            ->and((int) editedRow($order)?->gross_subtotal_minor)->toBe(250_00);
    });

    it('takes the coupon off once an edit leaves the order under its minimum', function () {
        $ready = Cx::ready();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15', with: ['minimum_minor' => 200_00]);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $order = Cx::place(Cx::start($ready['address'])->id)->id;
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT], ['sa']));
        $before = (int) editedRow($order)?->coupon_discount_minor;

        editTo($order, [[$ready['variants'][1], 1]]);

        expect($before)->toBe(37_50)
            ->and((int) editedRow($order)?->coupon_discount_minor)->toBe(0)
            ->and((int) DB::table('sales.order_lines')->where('order_id', $order)->sum('coupon_share_minor'))->toBe(0);
    });

    it('leaves the short-of-stock flag in place after an edit: only staff clear it', function () {
        ['order' => $order, 'variants' => $variants] = edited();
        $flagged = CarbonImmutable::now()->subHour();
        DB::table('sales.orders')->where('id', $order)->update(['short_of_stock_at' => $flagged]);

        editTo($order, [[$variants[0], 1], [$variants[1], 1]]);

        expect(editedRow($order)?->short_of_stock_at)->not->toBeNull();
    });
});
