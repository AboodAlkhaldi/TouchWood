<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeMetric;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Enums\HomePeriod;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Presentation\Home\AudienceCard;
use Modules\Sales\Presentation\Home\AverageOrderCard;
use Modules\Sales\Presentation\Home\BestSellersCard;
use Modules\Sales\Presentation\Home\DistributionCard;
use Modules\Sales\Presentation\Home\NewCustomersCard;
use Modules\Sales\Presentation\Home\OrdersFigureCard;
use Modules\Sales\Presentation\Home\OrdersNowCard;
use Modules\Sales\Presentation\Home\RecentOrdersCard;
use Modules\Sales\Presentation\Home\ReturnsWaitingCard;
use Modules\Sales\Presentation\Home\SalesByDayCard;
use Modules\Sales\Presentation\Home\SalesFigureCard;
use Modules\Sales\Presentation\Home\TermsMissingCard;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\DailyReportFixtures;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;
use Tests\TestCase;

use function Pest\Laravel\seed;

/*
| Sales' sections of the admin home (dashboard.md §2 B-J, §3, §5; flags-2026-11.md S3.3 F17), read through their
| cards with real orders placed through Sales' own use cases. The clock is TestCase::FROZEN_AT: Monday 2 November
| 2026, 15:00 in Riyadh - This Month runs from 1 November, against 1 to 2 October at 15:00.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->setLocale('en');
});

/** @return array{order: string, number: string, product: string, customer: string} an order placed now in the store */
function salesHomeOrder(string $storeCode = 'sa'): array
{
    $ready = Cx::ready(storeCode: $storeCode);
    $placed = Cx::place(Cx::start($ready['address'], storeCode: $storeCode)->id, $storeCode);

    return ['order' => $placed->id, 'number' => $placed->number, 'product' => $ready['product'], 'customer' => $ready['customer']];
}

function salesHomeGoods(string $order): int
{
    return (int) DB::table('sales.orders')->where('id', $order)->value('goods_total_minor');
}

/**
 * Staff of these stores holding these jobs, acting - half an hour after the orders, 15:30 in Riyadh: a period runs up
 * to the moment the page is read, not included, and the clock is frozen at the moment the orders were placed.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function salesHomeReader(array $permissions = [SalesPermissions::ORDER_VIEW], array $stores = ['sa']): void
{
    salesHomeLater();
    Fx::actAsStaff(Fx::staffWith($permissions, $stores));
}

function salesHomeLater(): void
{
    $later = CarbonImmutable::parse(TestCase::FROZEN_AT)->addMinutes(30);
    CarbonImmutable::setTestNow($later);
    Carbon::setTestNow($later);
}

/** @param  class-string<HomeCard>  $card */
function salesHomeRead(string $card, ?string $storeCode = 'sa', HomePeriod $period = HomePeriod::Month): ?HomeCardData
{
    $scope = $storeCode === null ? HomeScope::allStores($period) : HomeScope::store(StoreId::fromString(Fx::storeId($storeCode)), $period);

    return app($card)->data($scope);
}

function salesHomeMetric(?HomeCardData $data, int $index = 0): HomeMetric
{
    expect($data)->not->toBeNull();

    return $data->metrics[$index] ?? throw new LogicException('No such figure.');
}

/**
 * Two orders now (one a test order, one cancelled besides), one in the period before.
 *
 * @return array<string, array{order: string, number: string, product: string, customer: string}>
 */
function salesHomeAMonth(): array
{
    $first = salesHomeOrder();
    $second = salesHomeOrder();
    $before = salesHomeOrder();
    $test = salesHomeOrder();
    $cancelled = salesHomeOrder();
    // In the period before: 2 October, 11:00 in Riyadh.
    DB::table('sales.orders')->where('id', $before['order'])->update(['placed_at' => '2026-10-02 08:00:00+00']);
    DB::table('sales.orders')->where('id', $test['order'])->update(['is_test' => true]);
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_CANCEL], ['sa']));
    app(CancelOrderHandler::class)->handle(new CancelOrder($cancelled['order'], 'The customer called'));

    return ['first' => $first, 'second' => $second, 'before' => $before, 'test' => $test, 'cancelled' => $cancelled];
}

it('reads Sales, Orders and Average Order over the period against the one before, a test order and a cancelled one never counted', function () {
    $month = salesHomeAMonth();
    $now = salesHomeGoods($month['first']['order']) + salesHomeGoods($month['second']['order']);
    $before = salesHomeGoods($month['before']['order']);
    salesHomeReader();

    $sales = salesHomeMetric(salesHomeRead(SalesFigureCard::class))->lines[0];
    $orders = salesHomeMetric(salesHomeRead(OrdersFigureCard::class))->lines[0];
    $average = salesHomeMetric(salesHomeRead(AverageOrderCard::class))->lines[0];

    expect($now)->toBeGreaterThan(0)
        ->and($sales->value?->value)->toBe($now)
        // The riyal's own sign, as the panel writes money (frontend.md §1.8).
        ->and($sales->value?->money?->text)->toEndWith("\u{20C1}")
        ->and($sales->change?->percent)->toBe(round(($now - $before) / $before * 100, 1))
        // One point a day of the period, the 1st and the 2nd: everything on the 2nd.
        ->and($sales->trend)->toBe([0, $now])
        ->and($sales->note)->toBeNull()
        ->and($orders->value?->value)->toBe(2)
        ->and($orders->change?->percent)->toBe(100.0)
        ->and($orders->trend)->toBe([0, 2])
        ->and($average->value?->value)->toBe(intdiv($now, 2))
        ->and($average->change?->percent)->toBe(round((intdiv($now, 2) - $before) / $before * 100, 1));
});

it('shows "—" for an average with no order, and an average with no order before it as New', function () {
    salesHomeOrder();
    salesHomeReader([SalesPermissions::ORDER_VIEW], ['sa', 'eg']);

    $today = salesHomeMetric(salesHomeRead(AverageOrderCard::class, period: HomePeriod::Today))->lines[0];
    $none = salesHomeMetric(salesHomeRead(AverageOrderCard::class, 'eg'))->lines[0];

    expect($today->value)->not->toBeNull()
        ->and($today->change)->not->toBeNull()
        ->and($today->change?->percent)->toBeNull()
        ->and($none->value)->toBeNull()
        ->and($none->change)->toBeNull();
});

it('counts the orders waiting, to ship and on the way now, whatever the period, and those cancelled in the period', function () {
    salesHomeAMonth();
    // One more approved and not shipped, one more approved, paid and shipped: one in each of the other two.
    $approved = salesHomeOrder()['order'];
    $shipped = salesHomeOrder()['order'];
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($approved));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($shipped));
    app(RecordPaymentHandler::class)->handle(new RecordPayment($shipped, 'BANK_TRANSFER', 'T-1', null, '2026-11-02', (string) Str::uuid()));
    app(ShipOrderHandler::class)->handle(new ShipOrder($shipped, otherCarrier: 'SMSA', trackingNumber: 'T-1'));
    salesHomeReader();

    $week = salesHomeRead(OrdersNowCard::class, period: HomePeriod::Today);
    $labels = array_map(static fn (HomeMetric $metric): string => $metric->label, $week->metrics ?? []);

    // The order of the period before waits too: "now" is not the period's.
    expect($labels)->toBe(['Waiting', 'To Ship', 'On the Way', 'Cancelled and Came Back'])
        ->and(salesHomeMetric($week, 0)->lines[0]->value?->value)->toBe(3)
        ->and(salesHomeMetric($week, 0)->href)->toBe('/admin/orders?store=sa&tab=WAITING_APPROVAL')
        ->and(salesHomeMetric($week, 1)->lines[0]->value?->value)->toBe(1)
        ->and(salesHomeMetric($week, 1)->href)->toBe('/admin/orders?store=sa&tab=TO_SHIP')
        ->and(salesHomeMetric($week, 2)->lines[0]->value?->value)->toBe(1)
        ->and(salesHomeMetric($week, 2)->href)->toBe('/admin/orders?store=sa&tab=SHIPPED')
        ->and(salesHomeMetric($week, 3)->lines[0]->value?->value)->toBe(1)
        ->and(salesHomeMetric($week, 3)->lines[0]->change?->percent)->toBeNull()
        ->and(salesHomeMetric($week, 3)->href)->toBeNull();
});

it('lists the last orders placed, with the first line\'s name, how many more, the pieces and the status', function () {
    $month = salesHomeAMonth();
    salesHomeReader();

    $table = salesHomeRead(RecentOrdersCard::class)?->panels[0]->table;
    $rows = [];

    foreach ($table->rows ?? [] as $row) {
        $rows[explode(' · ', (string) $row->detail)[0]] = $row;
    }

    $second = $rows[$month['second']['number']] ?? null;

    // The test order is never listed; the cancelled one is, as placed; the oldest comes last.
    expect(array_keys($rows))->not->toContain($month['test']['number'])
        ->and(array_keys($rows))->toContain($month['cancelled']['number'])
        ->and(array_key_last($rows))->toBe($month['before']['number'])
        ->and($second?->detail)->toBe($month['second']['number'].' · +1 more')
        ->and($second?->cells[1]->value?->value)->toBe(3)
        ->and($second?->cells[3]->text)->toBe('Waiting for approval')
        ->and($second?->cells[3]->badge)->toBe('amber-subtle')
        ->and($second?->href)->toBe('/admin/orders/'.$month['second']['order'])
        ->and($rows[$month['cancelled']['number']]->cells[3]->text ?? null)->toBe('Cancelled');
});

it('lists the sizes sold most in the period with their pieces and Sales, and splits Sales by who ordered and by category', function () {
    $month = salesHomeAMonth();
    DB::table('sales.orders')->where('id', $month['second']['order'])->update(['audience' => 'COMPANY']);
    salesHomeReader();

    $best = salesHomeRead(BestSellersCard::class)?->panels[0]->table;
    $ring = salesHomeRead(AudienceCard::class)?->panels[0]->splits[0];
    $bars = salesHomeRead(DistributionCard::class)?->panels[0]->splits ?? [];
    $first = salesHomeGoods($month['first']['order']);
    $second = salesHomeGoods($month['second']['order']);

    // Each order holds two of its 100.00 size and one of its 50.00 one.
    expect($best?->rows)->toHaveCount(4)
        ->and($best?->rows[0]->cells[1]->value?->value)->toBe(2)
        ->and($best?->rows[0]->cells[0]->text)->toBe('—')
        ->and($ring?->shape)->toBe('ring')
        ->and($ring?->parts[0]->value->value)->toBe($first)
        ->and($ring?->parts[1]->value->value)->toBe($second)
        ->and($ring?->parts[1]->change?->percent)->toBeNull()
        ->and($ring?->total?->value)->toBe($first + $second)
        ->and($bars)->toHaveCount(2)
        // Each order's product is a new one, in its own category: two parts, half and half.
        ->and(array_map(static fn ($part): float => $part->share, $bars[0]->parts))->toBe([50.0, 50.0])
        ->and(array_sum(array_map(static fn ($part): int => (int) $part->value->value, $bars[0]->parts)))->toBe($first + $second)
        // Nothing paid yet: money received has no part.
        ->and($bars[1]->parts)->toBe([]);
});

it('gives the chart a point a day with the period before\'s Sales under the same day, and months for the Year', function () {
    $month = salesHomeAMonth();
    salesHomeReader();

    $series = salesHomeRead(SalesByDayCard::class)?->panels[0]->series;
    $year = salesHomeRead(SalesByDayCard::class, period: HomePeriod::Year)?->panels[0]->series;

    expect(array_map(static fn ($point): string => $point->at, $series->points ?? []))->toBe(['2026-11-01', '2026-11-02'])
        ->and($series?->points[1]->values['orders'])->toBe(2)
        ->and($series?->points[1]->values['before'])->toBe(salesHomeGoods($month['before']['order']))
        ->and($series?->lines[2]->dotted)->toBeTrue()
        ->and($year?->byMonth)->toBeTrue()
        ->and($year?->points)->toHaveCount(11)
        ->and($year?->points[9]->values['orders'])->toBe(1)
        ->and($year?->points[10]->values['orders'])->toBe(2);
});

it('counts the customers registered in the period by the store\'s day, against the one before', function () {
    $when = [
        // 1 November in Riyadh (UTC+3) starts at 21:00 UTC on 31 October: the first two are the 1st and the 2nd.
        'first' => '2026-10-31 21:00:00+00', 'second' => '2026-11-01 21:30:00+00', 'company' => '2026-11-02 06:00:00+00',
        // Just before the period, and in the period before (1 to 2 October at 15:00).
        'before' => '2026-10-31 20:59:59+00', 'beforePeriod' => '2026-10-01 09:00:00+00',
        'elsewhere' => '2026-11-02 06:00:00+00',
    ];
    $accounts = [
        'first' => Fx::customer('nc-first@example.test'), 'second' => Fx::customer('nc-second@example.test'),
        'company' => Fx::customer('nc-company@example.test', 'sa', 'company'), 'before' => Fx::customer('nc-before@example.test'),
        'beforePeriod' => Fx::customer('nc-before-period@example.test'), 'elsewhere' => Fx::customer('nc-elsewhere@example.test', 'eg'),
    ];

    foreach ($accounts as $name => $id) {
        DB::statement('UPDATE access.customers SET created_at = ?::timestamptz WHERE id = ?', [$when[$name], $id]);
    }

    salesHomeReader();
    $line = salesHomeMetric(salesHomeRead(NewCustomersCard::class))->lines[0];

    // The 1st: one; the 2nd: one individual and one company; another store's never. The period before runs 1 to
    // 2 October at 15:30, so only `beforePeriod` is in it: 3 against 1.
    expect($line->value?->value)->toBe(3)
        ->and($line->trend)->toBe([1, 2])
        ->and($line->change?->percent)->toBe(200.0);
});

it('lists every store for All Stores, the base store first, each in its own currency, and totals a count', function () {
    salesHomeOrder();
    salesHomeOrder('eg');
    salesHomeLater();
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    $sales = salesHomeMetric(salesHomeRead(SalesFigureCard::class, null));
    $orders = salesHomeMetric(salesHomeRead(OrdersFigureCard::class, null));
    $panels = salesHomeRead(SalesByDayCard::class, null)->panels ?? [];

    expect(array_map(static fn ($line): ?string => $line->store, $sales->lines))->toBe(['Saudi Arabia', 'Egypt', 'United Arab Emirates'])
        ->and($sales->lines[0]->value?->money?->text)->toEndWith("\u{20C1}")
        ->and($sales->lines[1]->value?->money?->text)->toEndWith('EGP')
        ->and($sales->total)->toBeNull()
        ->and($orders->total?->value?->value)->toBe(2)
        ->and(array_map(static fn ($panel): string => $panel->storeCode, $panels))->toBe(['sa', 'eg', 'ae']);
});

it('reads nothing for a store the reader does not hold, nor for All Stores unless they hold it in every store', function () {
    salesHomeOrder();
    salesHomeReader([SalesPermissions::ORDER_VIEW], ['sa', 'eg']);

    foreach ([SalesFigureCard::class, OrdersNowCard::class, RecentOrdersCard::class, BestSellersCard::class, DistributionCard::class, TermsMissingCard::class] as $card) {
        expect(salesHomeRead($card, 'ae'))->toBeNull()
            ->and(salesHomeRead($card, null))->toBeNull();
    }

    expect(salesHomeRead(SalesFigureCard::class, 'eg'))->not->toBeNull();
});

it('warns of each store with no Terms and Conditions published, and says nothing once every store has them', function () {
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    Cx::terms('sa');

    $rows = salesHomeRead(TermsMissingCard::class, null)->rows ?? [];

    expect(array_map(static fn ($row): string => $row->label, $rows))->toBe(['No Terms and Conditions: Egypt', 'No Terms and Conditions: United Arab Emirates'])
        ->and(salesHomeRead(TermsMissingCard::class, 'sa'))->toBeNull();

    Cx::terms('eg');
    Cx::terms('ae');
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(salesHomeRead(TermsMissingCard::class, null))->toBeNull();
});

it('counts the returns waiting for whoever manages returns, and says nothing with none', function () {
    salesHomeReader([SalesPermissions::RETURN_MANAGE]);
    expect(salesHomeRead(ReturnsWaitingCard::class))->toBeNull();

    DailyReportFixtures::aDay();
    salesHomeReader([SalesPermissions::RETURN_MANAGE]);
    $data = salesHomeRead(ReturnsWaitingCard::class);

    expect($data?->figures[0]->value)->toBe(1)
        ->and($data?->href)->toBe('/admin/returns?store=sa');

    // Viewing orders is not managing returns.
    salesHomeReader([SalesPermissions::ORDER_VIEW]);
    expect(salesHomeRead(ReturnsWaitingCard::class))->toBeNull();
});

it('shows the refunds under Sales, not taken off it', function () {
    DailyReportFixtures::aDay();
    salesHomeReader();

    $line = salesHomeMetric(salesHomeRead(SalesFigureCard::class))->lines[0];
    $sold = (int) DB::table('sales.orders')->where('is_test', false)->whereNotIn('status', ['CANCELLED', 'REJECTED', 'UNDELIVERED'])->sum('goods_total_minor');

    expect($line->value?->value)->toBe($sold)
        ->and($line->note)->toStartWith('Refunds ')
        ->and($line->note)->toEndWith("\u{20C1}");
});

it('reads the store\'s own day: an order at 22:00 UTC on 1 November is the 2nd in Riyadh', function () {
    $order = salesHomeOrder();
    DB::table('sales.orders')->where('id', $order['order'])->update(['placed_at' => '2026-11-01 22:00:00+00']);
    salesHomeReader();

    expect(salesHomeMetric(salesHomeRead(OrdersFigureCard::class))->lines[0]->trend)->toBe([0, 1])
        ->and(salesHomeMetric(salesHomeRead(OrdersFigureCard::class, period: HomePeriod::Today))->lines[0]->value?->value)->toBe(1)
        ->and(CarbonImmutable::now()->toIso8601String())->toBe('2026-11-02T12:30:00+00:00');
});
