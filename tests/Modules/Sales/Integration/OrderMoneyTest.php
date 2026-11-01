<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Application\Command\AllowShipUnpaid\AllowShipUnpaid;
use Modules\Sales\Application\Command\AllowShipUnpaid\AllowShipUnpaidHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RecordRefund\RecordRefund;
use Modules\Sales\Application\Command\RecordRefund\RecordRefundHandler;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecord;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecordHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\RefundTooLarge;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The money recorded by hand (sales.md §1.4, §1.9): payments and refunds — the amount in the order's own
| currency, a bank transfer or "other" with a note, a reference, the day; pressing twice records once; a
| refund never above what the order holds; a record never changed, voided once with a reason — a payment
| only while what was paid without it still covers the refunds (answer 42); "may ship unpaid" per order.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** An order placed in the 'sa' store; then staff with every money job there, acting. */
function moneyOrder(): string
{
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::PAYMENT_RECORD, SalesPermissions::ORDER_FULFIL, SalesPermissions::ORDER_APPROVE], ['sa']));

    return $order;
}

/** The whole balance still owed, as every payment is (owner, 2026-10-27). */
function moneyPay(string $order, string $way = 'BANK_TRANSFER', ?string $note = null, ?string $request = null, string $day = '2026-10-19', ?string $reference = 'T-1'): string
{
    return app(RecordPaymentHandler::class)->handle(new RecordPayment($order, $way, $reference, $note, $day, $request ?? (string) Str::uuid()));
}

function moneyDue(string $order): int
{
    return (int) DB::table('sales.orders')->where('id', $order)->value('due_minor');
}

/** What an edit raising the total leaves owed, by hand. */
function moneyRaise(string $order, int $by): void
{
    DB::table('sales.orders')->where('id', $order)->incrementEach(['due_minor' => $by, 'order_total_minor' => $by]);
}

function moneyRefund(string $order, string $amount, ?string $request = null): string
{
    return app(RecordRefundHandler::class)->handle(new RecordRefund($order, $amount, 'OTHER', null, 'Cash at the counter', '2026-10-19', $request ?? (string) Str::uuid()));
}

/** @return array{int, int} paid, refunded */
function moneyHeld(string $order): array
{
    $row = DB::table('sales.orders')->where('id', $order)->first(['paid_minor', 'refunded_minor']);

    return [(int) $row?->paid_minor, (int) $row?->refunded_minor];
}

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00')));

describe('recording', function () {
    it('records a payment and a refund, moving what the order holds, kept and audited', function () {
        $order = moneyOrder();

        $due = moneyDue($order);
        $payment = moneyPay($order);
        $refund = moneyRefund($order, '20.25');

        $row = DB::table('sales.payments')->where('id', $payment)->first();
        expect(moneyHeld($order))->toBe([$due, 20_25])
            ->and($due)->toBeGreaterThan(0)
            ->and([(int) $row?->amount_minor, $row?->way, $row?->reference, $row?->currency_code, $row?->happened_on])->toBe([$due, 'BANK_TRANSFER', 'T-1', 'SAR', '2026-10-19'])
            ->and(DB::table('sales.refunds')->where('id', $refund)->value('note'))->toBe('Cash at the counter')
            ->and(DB::table('sales.order_history')->where('order_id', $order)->orderBy('id')->pluck('kind')->all())->toBe(['PLACED', 'PAYMENT_RECORDED', 'REFUND_RECORDED'])
            ->and(Fx::audits('sales.payment.recorded', $payment))->toBe(1)
            ->and(Fx::audits('sales.refund.recorded', $refund))->toBe(1);
    });

    it('records a form sent twice once, and refuses its id on another order', function () {
        $order = moneyOrder();
        $request = (string) Str::uuid();

        $first = moneyPay($order, request: $request);
        $again = moneyPay($order, request: strtoupper($request));
        $other = Cx::place(Cx::start(Cx::ready()['address'])->id)->id;
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::PAYMENT_RECORD], ['sa']));

        expect($again)->toBe($first)
            ->and(moneyHeld($order))->toBe([moneyDue($order), 0])
            ->and(Pr::thrown(fn () => moneyPay($other, request: $request), InvalidSalesAttribute::class)->attribute)->toBe('request');
    });

    it('refuses what no hand record may be', function (Closure $record, string $field) {
        $order = moneyOrder();

        expect(Pr::thrown(fn () => $record($order), InvalidSalesAttribute::class)->attribute)->toBe($field)
            ->and(moneyHeld($order))->toBe([0, 0]);
    })->with([
        'a refund with more decimals than the currency' => [fn (string $order) => moneyRefund($order, '10.555'), 'amount'],
        'a refund of nothing' => [fn (string $order) => moneyRefund($order, '0'), 'amount'],
        'a refund below 0' => [fn (string $order) => moneyRefund($order, '-5'), 'amount'],
        'a refund not a number' => [fn (string $order) => moneyRefund($order, 'ten'), 'amount'],
        'online, which is stage 7' => [fn (string $order) => moneyPay($order, way: 'ONLINE'), 'way'],
        'no such way' => [fn (string $order) => moneyPay($order, way: 'CASH'), 'way'],
        'other with no note' => [fn (string $order) => moneyPay($order, way: 'OTHER'), 'note'],
        'a long reference' => [fn (string $order) => moneyPay($order, reference: str_repeat('a', 101)), 'reference'],
        'a day to come' => [fn (string $order) => moneyPay($order, day: '2026-10-21'), 'date'],
        'no such day' => [fn (string $order) => moneyPay($order, day: '2026-02-30'), 'date'],
        'not a day' => [fn (string $order) => moneyPay($order, day: 'yesterday'), 'date'],
        'not a form id' => [fn (string $order) => moneyPay($order, request: 'form-1'), 'request'],
    ]);

    it('takes a payment of today, and other with a note', function () {
        $order = moneyOrder();

        moneyPay($order, way: 'other', note: 'Paid at the shop', day: '2026-10-20', reference: null);

        expect(moneyHeld($order))->toBe([moneyDue($order), 0])
            ->and(DB::table('sales.payments')->value('reference'))->toBeNull();
    });

    it('refunds no more than the order holds', function () {
        $order = moneyOrder();
        $due = moneyDue($order);
        moneyPay($order);
        moneyRefund($order, '30.00');
        $left = number_format(($due - 30_00) / 100, 2, '.', '');

        expect(fn () => moneyRefund($order, number_format(($due - 30_00 + 1) / 100, 2, '.', '')))->toThrow(RefundTooLarge::class)
            ->and(moneyRefund($order, $left))->not->toBe('')
            ->and(moneyHeld($order))->toBe([$due, $due]);
    });

    it('takes the whole balance owed as the payment, and refuses one when nothing is owed', function () {
        $order = moneyOrder();
        $due = moneyDue($order);
        moneyPay($order);

        $paidUp = Pr::thrown(fn () => moneyPay($order), InvalidOrderChange::class);
        // An edit raised the total by 30.00: one payment more, of the 30.00 now owed.
        moneyRaise($order, 30_00);
        $second = moneyPay($order);

        expect($paidUp)->toBeInstanceOf(InvalidOrderChange::class)
            ->and((int) DB::table('sales.payments')->where('id', $second)->value('amount_minor'))->toBe(30_00)
            ->and(moneyHeld($order))->toBe([$due + 30_00, 0])
            ->and(DB::table('sales.payments')->count())->toBe(2);
    });

    it('refuses a payment when what is owed is no longer the balance the screen showed', function () {
        $order = moneyOrder();
        $shown = number_format(moneyDue($order) / 100, 2, '.', '');
        moneyRaise($order, 50_00);
        $pay = fn (string $shown) => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'T-1', null, '2026-10-19', (string) Str::uuid(), $shown));

        expect(fn () => $pay($shown))->toThrow(InvalidOrderChange::class)
            ->and(moneyHeld($order))->toBe([0, 0])
            ->and($pay(number_format(moneyDue($order) / 100, 2, '.', '')))->not->toBe('')
            ->and(moneyHeld($order))->toBe([moneyDue($order), 0]);
    });

    it('takes what is owed again after a refund left the order short', function () {
        $order = moneyOrder();
        $due = moneyDue($order);
        moneyPay($order);
        moneyRefund($order, '10.00');

        $again = moneyPay($order);

        expect((int) DB::table('sales.payments')->where('id', $again)->value('amount_minor'))->toBe(10_00)
            ->and(moneyHeld($order))->toBe([$due + 10_00, 10_00]);
    });

    it('answers an order of a store the reader does not cover as no order, and one without the job as not allowed', function () {
        $order = moneyOrder();

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::PAYMENT_RECORD], ['eg']));
        expect(fn () => moneyPay($order))->toThrow(OrderNotFound::class);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(fn () => moneyPay($order))->toThrow(Unauthorized::class)
            ->and(moneyHeld($order))->toBe([0, 0]);
    });
});

describe('voiding', function () {
    it('voids a payment while what was paid without it still covers the refunds, once', function () {
        $order = moneyOrder();
        $due = moneyDue($order);
        $big = moneyPay($order);
        moneyRaise($order, 20_00);
        $small = moneyPay($order);
        moneyRefund($order, '30.00');

        app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('payment', $small, 'Entered twice'));

        expect(moneyHeld($order))->toBe([$due, 30_00])
            ->and(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', $big, 'Wrong')))->toThrow(InvalidOrderChange::class)
            ->and(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', $small, 'Again')))->toThrow(InvalidOrderChange::class)
            ->and(DB::table('sales.payments')->where('id', $small)->value('void_reason'))->toBe('Entered twice')
            ->and(Fx::audits('sales.payment.voided', $small))->toBe(1)
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'MONEY_VOIDED')->count())->toBe(1);
    });

    it('voids a payment that leaves exactly the refunds covered, and a refund always', function () {
        $order = moneyOrder();
        $due = moneyDue($order);
        moneyPay($order);
        moneyRaise($order, 20_00);
        $second = moneyPay($order);
        $refund = moneyRefund($order, number_format($due / 100, 2, '.', ''));

        app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', $second, 'Bounced'));
        app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('REFUND', $refund, 'Not sent'));

        expect(moneyHeld($order))->toBe([$due, 0]);
    });

    it('refuses an unknown record, one of a store the reader does not cover, and no kind', function () {
        $order = moneyOrder();
        $payment = moneyPay($order);

        expect(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', strtolower((string) Str::ulid()), 'Why')))->toThrow(OrderNotFound::class)
            ->and(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('REFUND', $payment, 'Why')))->toThrow(OrderNotFound::class)
            ->and(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('GIFT', $payment, 'Why')))->toThrow(OrderNotFound::class)
            ->and(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', $payment, ' ')))->toThrow(InvalidSalesAttribute::class);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::PAYMENT_RECORD], ['eg']));
        expect(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', $payment, 'Why')))->toThrow(OrderNotFound::class)
            ->and(moneyHeld($order))->toBe([moneyDue($order), 0]);
    });

    it('keeps a record unchanged in the database too', function (string $sql, string $refusal) {
        $order = moneyOrder();
        $payment = moneyPay($order);
        moneyRefund($order, '1.00');

        expect(fn () => DB::statement(str_replace('{id}', $payment, $sql)))->toThrow(QueryException::class, $refusal);
    })->with([
        'deleted' => ["DELETE FROM sales.payments WHERE id = '{id}'", 'never deleted'],
        'its amount changed' => ["UPDATE sales.payments SET amount_minor = 1 WHERE id = '{id}'", 'only voided, once'],
        'nothing changed' => ["UPDATE sales.payments SET note = note WHERE id = '{id}'", 'only voided, once'],
        'voided by someone alone' => ["UPDATE sales.payments SET voided_by = recorded_by WHERE id = '{id}'", 'only voided, once'],
        'a refund deleted' => ["DELETE FROM sales.refunds WHERE order_id = (SELECT order_id FROM sales.payments WHERE id = '{id}')", 'never deleted'],
        'a refund changed' => ["UPDATE sales.refunds SET amount_minor = 1 WHERE order_id = (SELECT order_id FROM sales.payments WHERE id = '{id}')", 'only voided, once'],
        'refunds emptied' => ['TRUNCATE sales.refunds', 'append-only'],
        'voided with no reason' => ["UPDATE sales.payments SET voided_at = now() WHERE id = '{id}'", 'payments_void'],
        'emptied' => ['TRUNCATE sales.payments', 'append-only'],
    ]);
});

it('takes a void once in the database, and nothing after it', function () {
    $order = moneyOrder();
    $payment = moneyPay($order);

    DB::statement("UPDATE sales.payments SET voided_at = now(), voided_by = recorded_by, void_reason = 'a' WHERE id = ?", [$payment]);

    expect(fn () => DB::statement("UPDATE sales.payments SET void_reason = 'b' WHERE id = ?", [$payment]))->toThrow(QueryException::class, 'only voided, once');
});

describe('may ship unpaid', function () {
    it('lets staff allow an approved order to ship unpaid, and take it back, kept and audited', function () {
        $order = moneyOrder();
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));

        app(AllowShipUnpaidHandler::class)->handle(new AllowShipUnpaid($order, true));
        app(AllowShipUnpaidHandler::class)->handle(new AllowShipUnpaid($order, true));
        expect(DB::table('sales.orders')->where('id', $order)->value('may_ship_unpaid'))->toBeTrue();

        app(AllowShipUnpaidHandler::class)->handle(new AllowShipUnpaid($order, false));

        expect(DB::table('sales.orders')->where('id', $order)->value('may_ship_unpaid'))->toBeFalse()
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'SHIP_UNPAID_ALLOWED')->count())->toBe(2)
            ->and(Fx::audits('sales.order.ship_unpaid_allowed', $order))->toBe(2);
    });

    it('refuses an order not approved, or shipped', function (bool $approve, bool $ship) {
        $order = moneyOrder();

        if ($approve) {
            app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        }

        if ($ship) {
            DB::table('sales.orders')->where('id', $order)->update(['shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA']);
        }

        expect(fn () => app(AllowShipUnpaidHandler::class)->handle(new AllowShipUnpaid($order, true)))->toThrow(InvalidOrderChange::class);
    })->with([
        'waiting' => [false, false],
        'shipped' => [true, true],
    ]);
});

describe('what the review asked for', function () {
    it('takes a day that is already today in the store, though not yet in UTC', function () {
        $order = moneyOrder();
        // 22:30 UTC is 01:30 the next morning in Riyadh.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 22:30:00', 'UTC'));

        moneyPay($order, day: '2026-10-21');
        moneyRaise($order, 1_00);

        expect(DB::table('sales.payments')->value('happened_on'))->toBe('2026-10-21')
            ->and(fn () => moneyPay($order, day: '2026-10-22'))->toThrow(InvalidSalesAttribute::class);
    });

    it('audits what staff type as written only', function () {
        $order = moneyOrder();

        $payment = moneyPay($order, way: 'OTHER', note: 'Khalid paid 0501234567', reference: 'IBAN SA4420000001234567891234');

        $changes = (string) DB::table('platform.audit_entries')->where('action', 'sales.payment.recorded')->where('subject_id', $payment)->value('changes');
        expect($changes)->toContain('reference')
            ->and($changes)->toContain('note')
            ->and($changes)->not->toContain('0501234567')
            ->and($changes)->not->toContain('SA4420000001234567891234');
    });

    it('refuses a refund past any order', function () {
        $order = moneyOrder();
        moneyPay($order);

        expect(Pr::thrown(fn () => moneyRefund($order, '10000000000.01'), InvalidSalesAttribute::class)->attribute)->toBe('amount')
            ->and(fn () => moneyRefund($order, '10000000000.00'))->toThrow(RefundTooLarge::class);
    });

    it('refuses in the database a record in another currency or of another store', function (array $with) {
        $order = moneyOrder();
        $row = [
            'id' => strtolower((string) Str::ulid()), 'order_id' => $order, 'store_id' => Fx::storeId('sa'), 'currency_code' => 'SAR',
            'amount_minor' => 100, 'way' => 'BANK_TRANSFER', 'happened_on' => '2026-10-19', 'request_id' => (string) Str::uuid(), 'recorded_at' => CarbonImmutable::now(),
        ];

        expect(fn () => DB::table('sales.payments')->insert([...$row, ...$with]))->toThrow(QueryException::class, 'payments_order');
    })->with([
        'another currency' => [['currency_code' => 'EGP']],
        'another store' => [fn () => ['store_id' => Fx::storeId('eg')]],
    ]);

    it('lets only a fulfiller allow shipping unpaid', function () {
        $order = moneyOrder();
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::PAYMENT_RECORD], ['sa']));

        expect(fn () => app(AllowShipUnpaidHandler::class)->handle(new AllowShipUnpaid($order, true)))->toThrow(Unauthorized::class);
    });
});
