<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\RegisterApplePayDomain\RegisterApplePayDomain;
use Modules\Payments\Application\Command\RegisterApplePayDomain\RegisterApplePayDomainHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Command\SwitchMode\SwitchMode;
use Modules\Payments\Application\Command\SwitchMode\SwitchModeHandler;
use Modules\Payments\Application\Gateway\GatewayAccess;
use Modules\Payments\Application\Gateway\GatewayAccesses;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\Query\ViewGateway\ViewGateway;
use Modules\Payments\Application\Query\ViewGateway\ViewGatewayHandler;
use Modules\Payments\Domain\Exception\GatewayNotSetUp;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoGatewayKey;
use Modules\Payments\Domain\Exception\NoOnlinePayment;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\OnlinePaymentReason;
use Modules\Payments\Public\Enums\PaymentAudience;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Events\GatewayKeyRejected;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\MyFatoorahAnswers as Answers;

use function Pest\Laravel\seed;

/*
| A session for the form (payments.md §1.3, §1.4 step 0), what MyFatoorah's refusal of a key does (§1.2), and
| Apple Pay's domains (§1.2, gap 8.5), against a faked MyFatoorah.
*/

uses(RefreshDatabase::class);

const SESSIONS_MF = 'https://apitest.myfatoorah.com';

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

function sessionsGateway(string $code = 'sa', string $mode = 'TEST', bool $on = true): string
{
    $store = Fx::storeId($code);
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, $mode, 'sk_'.strtolower($mode).'_key_ABC123', 'whsec_secret_1', '2027-12-31'));

    if ($on) {
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));
    }

    return $store;
}

function sessionsAsk(string $store, ?Money $amount = null, ?DateTimeImmutable $expiresAt = null, string $locale = 'en', string $currency = 'SAR'): SessionRequestDto
{
    return new SessionRequestDto(StoreId::fromString($store), 'quote-1', 'buyer-1', $amount ?? Money::of(118050, $currency), $locale, $expiresAt ?? new CarbonImmutable('+15 minutes'));
}

function sessionsRejected(): int
{
    return DB::table('public.outbox_messages')->where('type', GatewayKeyRejected::class)->count();
}

describe('opening a session', function () {
    it('asks MyFatoorah for the store\'s amount with the mode\'s key, and writes down whose it is', function () {
        Http::fake([SESSIONS_MF.'/v3/sessions' => Http::response(Answers::session(), 201)]);
        $store = sessionsGateway();

        $session = app(PaymentsApi::class)->openSession(sessionsAsk($store));

        $request = Http::recorded()->map(fn (array $pair): Request => $pair[0])->sole();
        $row = DB::table('payments.sessions')->sole();

        expect($request->header('Authorization'))->toBe(['Bearer sk_test_key_ABC123'])
            ->and($request->data()['Order'])->toBe(['Amount' => 1180.5, 'Currency' => 'SAR'])
            ->and([$session->sessionId, $session->mode, $session->scriptUrl, $session->applePay])
            ->toBe(['KWT-a5f60574-ddf0-46b9-a30e-102b231604aa', PaymentMode::Test, 'https://demo.myfatoorah.com/sessions/v1/session.js', false])
            ->and([$row->id, $row->store_id, $row->mode, $row->gateway, $row->owner_id, $row->buyer_id, (int) $row->amount_minor, $row->currency_code, $row->used_by])
            ->toBe(['KWT-a5f60574-ddf0-46b9-a30e-102b231604aa', $store, 'TEST', 'MYFATOORAH', 'quote-1', 'buyer-1', 118050, 'SAR', null])
            ->and(CarbonImmutable::parse($row->expires_at)->getTimestamp())->toBe($session->expiresAt->getTimestamp());
    });

    it('uses the store\'s own live address and script in live mode', function () {
        Http::fake(['https://api-sa.myfatoorah.com/v3/sessions' => Http::response(Answers::session('SAU-1'), 201)]);
        $store = sessionsGateway(mode: 'LIVE');

        $session = app(PaymentsApi::class)->openSession(sessionsAsk($store));

        expect([$session->sessionId, $session->mode, $session->scriptUrl])->toBe(['SAU-1', PaymentMode::Live, 'https://sa.myfatoorah.com/sessions/v1/session.js']);
    });

    it('asks for nothing in a store with no gateway, one switched off, or one whose key was refused', function (Closure $set) {
        Http::fake();
        $store = $set();

        expect(fn () => app(PaymentsApi::class)->openSession(sessionsAsk($store)))->toThrow(NoOnlinePayment::class);
        Http::assertNothingSent();
    })->with([
        'no gateway' => [fn () => Fx::storeId('sa')],
        'off' => [fn () => sessionsGateway(on: false)],
        'refused' => [function () {
            $store = sessionsGateway();
            DB::table('payments.gateway_keys')->where('current', true)->update(['rejected_at' => now()]);

            return $store;
        }],
    ]);

    it('refuses a store the gateway is not offered in, whose gateway somehow stands', function () {
        Http::fake();
        $store = Fx::storeId('ae');
        DB::table('payments.gateways')->insert(['store_id' => $store, 'gateway' => 'MYFATOORAH', 'active' => true, 'mode' => 'TEST', 'apple_pay_domains' => '[]', 'created_by' => Fx::staff(superAdmin: true), 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => app(PaymentsApi::class)->openSession(sessionsAsk($store)))->toThrow(NoOnlinePayment::class);
        Http::assertNothingSent();
    });

    it('refuses an amount of nothing, of another currency, or a session already over, before any call', function (Closure $ask) {
        Http::fake();
        $store = sessionsGateway();

        expect(fn () => app(PaymentsApi::class)->openSession($ask($store)))->toThrow(InvalidPaymentsInput::class);
        Http::assertNothingSent();
    })->with([
        'nothing' => [fn (string $store) => sessionsAsk($store, Money::of(0, 'SAR'))],
        'less than nothing' => [fn (string $store) => sessionsAsk($store, Money::of(-1, 'SAR'))],
        'another currency' => [fn (string $store) => sessionsAsk($store, Money::of(100, 'EGP'))],
        'over' => [fn (string $store) => sessionsAsk($store, expiresAt: new CarbonImmutable('-1 second'))],
    ]);

    it('writes nothing when MyFatoorah says no, or does not answer', function (int $status, string $thrown) {
        Http::fake([SESSIONS_MF.'/*' => Http::response(Answers::refusal(), $status)]);
        $store = sessionsGateway();

        expect(fn () => app(PaymentsApi::class)->openSession(sessionsAsk($store)))->toThrow($thrown)
            ->and(DB::table('payments.sessions')->count())->toBe(0)
            ->and(DB::table('payments.gateway_keys')->whereNotNull('rejected_at')->count())->toBe(0);
    })->with([
        'no' => [400, GatewayRefused::class],
        'no answer' => [503, GatewayUnreachable::class],
    ]);

    it('says whether this domain is registered for Apple Pay, in this mode', function () {
        config(['app.url' => 'https://Shop.Example.com/']);
        Http::fake([
            SESSIONS_MF.'/v3/sessions' => fn () => Http::response(Answers::session('KWT-'.Str::uuid()), 201),
            SESSIONS_MF.'/v2/RegisterApplePayDomain' => Http::response(['IsSuccess' => true]),
            'https://api-sa.myfatoorah.com/*' => Http::response(['IsSuccess' => true, 'Data' => ['SessionId' => 'SAU-2', 'SessionExpiry' => '2027-01-01T10:15:00Z']], 201),
        ]);
        $store = sessionsGateway();
        sessionsGateway(mode: 'LIVE', on: false);

        expect(app(PaymentsApi::class)->openSession(sessionsAsk($store))->applePay)->toBeFalse();

        app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, 'TEST', 'shop.example.com'));

        expect(app(PaymentsApi::class)->openSession(sessionsAsk($store))->applePay)->toBeTrue();

        app(SwitchModeHandler::class)->handle(new SwitchMode($store, 'LIVE'));

        expect(app(PaymentsApi::class)->openSession(sessionsAsk($store))->applePay)->toBeFalse();
    });
});

describe('a key MyFatoorah refuses (§1.2)', function () {
    it('stops online payment by itself, tells the Super Admins once, and comes back with a new key', function () {
        Http::fakeSequence(SESSIONS_MF.'/v3/sessions')
            ->push(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401)
            ->push(Answers::session(), 201);
        $store = sessionsGateway();

        expect(fn () => app(PaymentsApi::class)->openSession(sessionsAsk($store)))->toThrow(NoOnlinePayment::class)
            ->and(app(PaymentsApi::class)->availability(StoreId::fromString($store), PaymentAudience::Individual)->reason)->toBe(OnlinePaymentReason::KeyRejected)
            ->and(sessionsRejected())->toBe(1)
            ->and(json_decode((string) DB::table('public.outbox_messages')->where('type', GatewayKeyRejected::class)->value('payload'), true))
            ->toMatchArray(['storeId' => $store, 'mode' => 'TEST']);

        // Refused, it is not asked again.
        expect(fn () => app(PaymentsApi::class)->openSession(sessionsAsk($store)))->toThrow(NoOnlinePayment::class);
        Http::assertSentCount(1);

        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'TEST', 'sk_test_key_NEW123', 'whsec_secret_2', '2027-12-31'));

        expect(app(PaymentsApi::class)->availability(StoreId::fromString($store), PaymentAudience::Individual)->available)->toBeTrue()
            ->and(app(PaymentsApi::class)->openSession(sessionsAsk($store))->mode)->toBe(PaymentMode::Test)
            ->and(sessionsRejected())->toBe(1);
    });

    it('tells nobody of the same refusal twice', function () {
        $store = sessionsGateway();
        $dto = app(PlatformApi::class)->store(StoreId::fromString($store)) ?? throw new LogicException('No store');
        $access = app(GatewayAccesses::class)->current($dto, PaymentMode::Test) ?? throw new LogicException('No key');

        app(GatewayAccesses::class)->refused($access);
        app(GatewayAccesses::class)->refused($access);

        expect(sessionsRejected())->toBe(1);
    });

    it('sends nothing and stops nothing for an earlier key kept for old attempts', function () {
        $store = sessionsGateway();
        $old = (string) DB::table('payments.gateway_keys')->where('store_id', $store)->where('current', true)->value('id');
        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'TEST', 'sk_test_key_NEW123', 'whsec_secret_2', '2027-12-31'));

        app(GatewayAccesses::class)->refused(new GatewayAccess($store, PaymentMode::Test, SESSIONS_MF, $old, 'sk_test_key_ABC123'));

        expect(sessionsRejected())->toBe(0)
            ->and(DB::table('payments.gateway_keys')->whereNotNull('rejected_at')->count())->toBe(0)
            ->and(app(PaymentsApi::class)->availability(StoreId::fromString($store), PaymentAudience::Individual)->available)->toBeTrue();
    });

    it('reads the key back only for a call', function () {
        $store = sessionsGateway();
        $id = (string) DB::table('payments.gateway_keys')->where('store_id', $store)->value('id');

        expect(app(Gateways::class)->readKey($id))->toBe('sk_test_key_ABC123')
            ->and(app(Gateways::class)->secrets($store, PaymentMode::Test))->toBe(['whsec_secret_1']);
    });
});

describe('registering a domain for Apple Pay (§1.2, gap 8.5)', function () {
    it('registers per mode with that mode\'s key, audited, once', function () {
        Http::fake([SESSIONS_MF.'/v2/RegisterApplePayDomain' => Http::response(['IsSuccess' => true])]);
        $store = sessionsGateway();

        app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, 'test', ' Shop.Example.COM '));
        app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, 'TEST', 'shop.example.com'));

        $view = app(ViewGatewayHandler::class)->handle(new ViewGateway($store));
        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

        expect($view->applePayDomains)->toBe([['domain' => 'shop.example.com', 'mode' => 'TEST']])
            ->and($requests->count())->toBe(2)
            ->and($requests->firstOrFail()->data())->toBe(['DomainName' => 'shop.example.com'])
            ->and($requests->firstOrFail()->header('Authorization'))->toBe(['Bearer sk_test_key_ABC123'])
            ->and(Fx::audits('payments.gateway.apple_pay_registered', $store))->toBe(1);
    });

    it('keeps a domain when the gateway is switched or moved to the other mode', function () {
        Http::fake([SESSIONS_MF.'/v2/RegisterApplePayDomain' => Http::response(['IsSuccess' => true])]);
        $store = sessionsGateway();
        sessionsGateway(mode: 'LIVE', on: false);

        app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, 'TEST', 'shop.example.com'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, false));
        app(SwitchModeHandler::class)->handle(new SwitchMode($store, 'LIVE'));

        expect(app(ViewGatewayHandler::class)->handle(new ViewGateway($store))->applePayDomains)->toBe([['domain' => 'shop.example.com', 'mode' => 'TEST']]);
    });

    it('refuses what is not a domain, a mode, or a mode with no key, before any call', function (string $mode, string $domain, string $thrown) {
        Http::fake();
        $store = sessionsGateway();

        expect(fn () => app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, $mode, $domain)))->toThrow($thrown);
        Http::assertNothingSent();
    })->with([
        'with the scheme' => ['TEST', 'https://shop.example.com', InvalidPaymentsInput::class],
        'with a path' => ['TEST', 'shop.example.com/pay', InvalidPaymentsInput::class],
        'no dot' => ['TEST', 'localhost', InvalidPaymentsInput::class],
        'an address' => ['TEST', '127.0.0.1', InvalidPaymentsInput::class],
        'not a mode' => ['SOMETIMES', 'shop.example.com', InvalidPaymentsInput::class],
        'no key in the mode' => ['LIVE', 'shop.example.com', NoGatewayKey::class],
    ]);

    it('refuses a store with no gateway', function () {
        expect(fn () => app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain(Fx::storeId('sa'), 'TEST', 'shop.example.com')))->toThrow(GatewayNotSetUp::class);
    });

    it('lists nothing when MyFatoorah says no, and marks a key it refuses', function () {
        Http::fakeSequence(SESSIONS_MF.'/v2/RegisterApplePayDomain')
            ->push(Answers::refusal('The domain is not verified'), 400)
            ->push(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401);
        $store = sessionsGateway();

        expect(fn () => app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, 'TEST', 'shop.example.com')))->toThrow(GatewayRefused::class)
            ->and(sessionsRejected())->toBe(0)
            ->and(fn () => app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, 'TEST', 'shop.example.com')))->toThrow(GatewayRefused::class)
            ->and(sessionsRejected())->toBe(1)
            ->and(app(ViewGatewayHandler::class)->handle(new ViewGateway($store))->applePayDomains)->toBe([])
            ->and(Fx::audits('payments.gateway.apple_pay_registered', $store))->toBe(0);
    });

    it('is a Super Admin\'s alone, even an admin\'s with the job in hand', function () {
        Http::fake();
        $store = sessionsGateway();
        Fx::actAsStaff(Fx::staffWith([PaymentsPermissions::BANK_ACCOUNT_UPDATE], ['sa'], RoleLevel::Admin));

        expect(fn () => app(RegisterApplePayDomainHandler::class)->handle(new RegisterApplePayDomain($store, 'TEST', 'shop.example.com')))->toThrow(Unauthorized::class);
        Http::assertNothingSent();
    });
});

it('shows each mode\'s notice address on the gateway page, to paste into MyFatoorah\'s portal', function () {
    config(['app.url' => 'https://shop.example.com']);
    $store = sessionsGateway();

    expect(app(ViewGatewayHandler::class)->handle(new ViewGateway($store))->noticeAddresses)->toBe([
        'TEST' => "https://shop.example.com/payments/notice/{$store}/test",
        'LIVE' => "https://shop.example.com/payments/notice/{$store}/live",
    ]);
});

it('never names a real store\'s key in a stored session', function () {
    Http::fake([SESSIONS_MF.'/v3/sessions' => Http::response(Answers::session(), 201)]);
    $store = sessionsGateway();

    app(PaymentsApi::class)->openSession(sessionsAsk($store));

    expect(Str::contains((string) json_encode(DB::table('payments.sessions')->get()), ['sk_test_key_ABC123', 'the-session-encryption-key']))->toBeFalse()
        ->and(Str::contains((string) json_encode(DB::table('payments.calls')->get()), ['sk_test_key_ABC123', 'the-session-encryption-key']))->toBeFalse();
});
