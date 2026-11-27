<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Ops\Application\OpsSettings;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\OrderMessageLineDto;
use Modules\Sales\Public\Events\OrderApproved;
use Modules\Sales\Public\Events\OrderDelivered;
use Modules\Sales\Public\Events\OrderPlaced;
use Modules\Sales\Public\Events\OrderShipped;
use Shared\Domain\ValueObject\Money;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;

use function Pest\Laravel\seed;

/*
| Order updates by WhatsApp (ops.md §9.10): C1, C4 and C5 go beside the email to a customer whose phone is verified,
| once its template is named; the parameters in the template's order, "—" for no tracking number, the store's working
| days for the shipped message; nothing for an unverified phone, a kind not named, or the other kinds. Sales' read is a
| stand-in; Access, Platform and Ops are real; Meta is faked. The queue is `sync`.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    RecordingSecurityMessages::install();
    CapturedEmails::install();
    config(['services.whatsapp' => [
        'graph_version' => 'v26.0',
        'graph_url' => 'https://graph.facebook.com',
        'phone_number_id' => '1234567890',
        'access_token' => 'token-for-tests',
        'code_template' => 'touchwood_code',
        'app_secret' => 'secret-for-tests',
        'verify_token' => 'verify-for-tests',
        'main_number' => '+966 50 000 0000',
        'alert_key_expiring' => 'touchwood_key_expiring',
        'alert_key_refused' => 'touchwood_key_refused',
        'order_placed' => 'touchwood_order_placed',
        'order_shipped' => 'touchwood_order_shipped',
        'order_delivered' => 'touchwood_order_delivered',
    ]]);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.ORDER']]], 200)]);
    cache()->flush();
});

/** A customer of the KSA store with this verified phone, or with none, in this language. */
function orderUpdatesCustomer(bool $verified = true, string $locale = 'en', string $phone = '+966501234567'): string
{
    $id = Fx::customer('sara.'.Str::lower(Str::random(6)).'@example.test');
    // A phone is kept only once verified (the table holds both or neither): "not verified" is no phone at all.
    DB::table('access.customers')->where('id', $id)->update(['phone' => $verified ? $phone : null, 'phone_verified_at' => $verified ? now() : null, 'locale' => $locale]);

    cache()->flush();

    return $id;
}

/** Sales' read for messages: order SA-0007 of this customer, shipped with this carrier and tracking number. */
function orderUpdatesOrder(string $customerId, ?string $carrier = 'SMSA', ?string $tracking = null): OrderMessageDto
{
    $order = new OrderMessageDto(
        strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), $customerId, 'en', 'APPROVED', 'SHIPPED', 'BANK_TRANSFER',
        [new OrderMessageLineDto('Chair', [['attribute' => 'Colour', 'value' => 'Brown']], 2, Money::of(20000, 'SAR'), false)],
        Money::of(20000, 'SAR'), Money::of(0, 'SAR'), Money::of(0, 'SAR'), Money::of(2500, 'SAR'), Money::of(3375, 'SAR'), Money::of(25875, 'SAR'),
        $carrier, $tracking, null, null, 'Sara Ali', 'King Fahd Road 12, Riyadh',
    );
    $sales = Mockery::mock(SalesReports::class);
    $sales->allows('orderForMessage')->andReturn($order);
    $sales->allows('returnForMessage')->andReturn(null);
    $sales->allows('moneyRecordForMessage')->andReturn(null);
    app()->instance(SalesReports::class, $sales);

    return $order;
}

/** The KSA store's working days for the shipped message. */
function orderUpdatesDays(string $days): void
{
    DB::table('platform.settings')->insert(['store_id' => Fx::storeId('sa'), 'key' => OpsSettings::DELIVERY_DAYS, 'value' => json_encode($days), 'updated_at' => now()]);
    cache()->flush();
}

/** @return list<string> "KIND CHANNEL STATUS" of the customer's rows */
function orderUpdatesSent(string $customerId): array
{
    return array_values(DB::table('ops.sent_messages')->where('customer_id', strtolower($customerId))->orderBy('created_at')->orderBy('channel')->get()
        ->map(static fn (object $row): string => "{$row->kind} {$row->channel} {$row->status}")->all());
}

/** @return list<array{name: string, language: string, parameters: list<string>, to: string}> what Meta was asked to send */
function orderUpdatesToMeta(): array
{
    return array_values(array_map(static function (array $pair): array {
        /** @var Request $request */
        [$request] = $pair;
        /** @var array{to: string, template: array{name: string, language: array{code: string}, components: list<array{parameters: list<array{text: string}>}>}} $data */
        $data = $request->data();
        $template = $data['template'];

        return [
            'name' => $template['name'],
            'language' => $template['language']['code'],
            'parameters' => array_map(static fn (array $parameter): string => $parameter['text'], $template['components'][0]['parameters']),
            'to' => $data['to'],
        ];
    }, Http::recorded()->all()));
}

it('sends the placed update beside the email: first name and order number, in the customer\'s language, recorded SENT', function () {
    $sara = orderUpdatesCustomer();
    $order = orderUpdatesOrder($sara);

    event(new OrderPlaced((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(orderUpdatesToMeta())->toBe([['name' => 'touchwood_order_placed', 'language' => 'en', 'parameters' => ['Sara', 'SA-0007'], 'to' => '+966501234567']])
        ->and(orderUpdatesSent($sara))->toContain('order_taken EMAIL SENT', 'order_taken WHATSAPP SENT')
        ->and(DB::table('ops.sent_messages')->where('channel', 'WHATSAPP')->value('provider_id'))->toBe('wamid.ORDER');
});

it('sends the shipped update with the carrier, "—" for no tracking number and the store\'s working days, in Arabic for an Arabic customer', function () {
    $sara = orderUpdatesCustomer(locale: 'ar');
    $order = orderUpdatesOrder($sara, 'SMSA', null);
    orderUpdatesDays('4-7');

    event(new OrderShipped((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(orderUpdatesToMeta())->toBe([['name' => 'touchwood_order_shipped', 'language' => 'ar', 'parameters' => ['Sara', 'SA-0007', 'SMSA', '—', '4-7'], 'to' => '+966501234567']])
        ->and(orderUpdatesSent($sara))->toContain('order_shipped WHATSAPP SENT');
});

it('sends the tracking number when there is one, and the delivered update with name and number only', function () {
    $sara = orderUpdatesCustomer();
    $order = orderUpdatesOrder($sara, 'Aramex', '123456789');
    orderUpdatesDays('3');

    event(new OrderShipped((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
    event(new OrderDelivered((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(array_map(static fn (array $sent): array => [$sent['name'], $sent['parameters']], orderUpdatesToMeta()))->toBe([
        ['touchwood_order_shipped', ['Sara', 'SA-0007', 'Aramex', '123456789', '3']],
        ['touchwood_order_delivered', ['Sara', 'SA-0007']],
    ]);
});

it('fails the shipped update at once when the store has no working days, and still sends the email', function () {
    $sara = orderUpdatesCustomer();
    $order = orderUpdatesOrder($sara);

    event(new OrderShipped((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(orderUpdatesToMeta())->toBe([])
        ->and(orderUpdatesSent($sara))->toContain('order_shipped EMAIL SENT', 'order_shipped WHATSAPP FAILED');
});

it('sends nothing by WhatsApp to a customer with no verified phone, and the email still goes', function () {
    $sara = orderUpdatesCustomer(verified: false);
    $order = orderUpdatesOrder($sara);

    event(new OrderPlaced((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(orderUpdatesToMeta())->toBe([])
        ->and(orderUpdatesSent($sara))->toBe(['order_taken EMAIL SENT']);
});

it('sends nothing by WhatsApp for a kind whose template is not named, and writes no row for it', function () {
    config(['services.whatsapp.order_placed' => '']);
    $sara = orderUpdatesCustomer();
    $order = orderUpdatesOrder($sara);

    event(new OrderPlaced((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(orderUpdatesToMeta())->toBe([])
        ->and(orderUpdatesSent($sara))->toBe(['order_taken EMAIL SENT']);
});

it('sends nothing by WhatsApp for the other order moments', function () {
    $sara = orderUpdatesCustomer();
    $order = orderUpdatesOrder($sara);

    event(new OrderApproved((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(orderUpdatesToMeta())->toBe([])
        ->and(orderUpdatesSent($sara))->toBe(['order_approved EMAIL SENT']);
});

it('removes the row and sends nothing when the order is no longer the customer\'s', function () {
    $sara = orderUpdatesCustomer();
    $other = orderUpdatesCustomer(phone: '+966507654321');
    $order = orderUpdatesOrder($other);

    event(new OrderPlaced((string) Str::uuid(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

    expect(orderUpdatesToMeta())->toBe([])
        ->and(array_filter(orderUpdatesSent($sara), static fn (string $row): bool => str_contains($row, 'WHATSAPP')))->toBe([]);
});

it('takes "4-7" or "3" as the store\'s working days, and refuses anything else', function () {
    $registry = app(InMemorySettingsRegistry::class);
    $definition = $registry->definition(OpsSettings::DELIVERY_DAYS) ?? throw new LogicException('Not defined.');

    foreach (['4-7', '3', '10-14', ''] as $days) {
        expect($registry->validationProblem($definition, $days))->toBeNull($days);
    }

    foreach (['four', '4 - 7', '4-7-9', '123', '4-'] as $days) {
        expect($registry->validationProblem($definition, $days))->not->toBeNull($days);
    }
});
