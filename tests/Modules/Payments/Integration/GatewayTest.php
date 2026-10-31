<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Command\SwitchMode\SwitchMode;
use Modules\Payments\Application\Command\SwitchMode\SwitchModeHandler;
use Modules\Payments\Application\Gateway\KeyExpiryWarnings;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\PaymentsSettings;
use Modules\Payments\Application\Query\ViewGateway\ViewGateway;
use Modules\Payments\Application\Query\ViewGateway\ViewGatewayHandler;
use Modules\Payments\Domain\Exception\GatewayNotOffered;
use Modules\Payments\Domain\Exception\GatewayNotSetUp;
use Modules\Payments\Domain\Exception\HoldTooShort;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Exception\NoGatewayKey;
use Modules\Payments\Domain\Model\Gateway;
use Modules\Payments\Domain\Model\GatewayKey;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Enums\OnlinePaymentReason;
use Modules\Payments\Public\Enums\PaymentAudience;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Events\GatewayKeyExpiring;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

/** The key test, faked: no test calls MyFatoorah. Records each call; throws what it is told to. */
final class FakeKeyTester implements KeyTester
{
    /** @var list<array{string, string, string}> */
    public array $calls = [];

    public ?Throwable $fails = null;

    public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void
    {
        $this->calls[] = [$apiBase, $key, $currencyCode];

        if ($this->fails !== null) {
            throw $this->fails;
        }
    }
}

/** The gateways as they are, but the keys as an earlier read saw them - another run's mark not yet seen. */
final readonly class StaleKeys implements Gateways
{
    /**
     * @param  list<GatewayKey>  $keys
     */
    public function __construct(private Gateways $inner, private array $keys) {}

    public function lockStore(string $storeId): void
    {
        $this->inner->lockStore($storeId);
    }

    public function of(string $storeId): ?Gateway
    {
        return $this->inner->of($storeId);
    }

    public function create(string $storeId, PaymentMode $mode, string $staffId, DateTimeImmutable $now): void
    {
        $this->inner->create($storeId, $mode, $staffId, $now);
    }

    public function save(Gateway $gateway, DateTimeImmutable $now): void
    {
        $this->inner->save($gateway, $now);
    }

    public function currentKey(string $storeId, PaymentMode $mode): ?GatewayKey
    {
        return $this->inner->currentKey($storeId, $mode);
    }

    public function addKey(string $storeId, PaymentMode $mode, #[SensitiveParameter] string $key, #[SensitiveParameter] string $secret, DateTimeImmutable $expiresOn, string $staffId, DateTimeImmutable $now): string
    {
        return $this->inner->addKey($storeId, $mode, $key, $secret, $expiresOn, $staffId, $now);
    }

    public function currentKeys(): array
    {
        return $this->keys;
    }

    public function markWarned(string $keyId, int $days): bool
    {
        return $this->inner->markWarned($keyId, $days);
    }
}

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->instance(FakeKeyTester::class, new FakeKeyTester);
    app()->instance(KeyTester::class, app(FakeKeyTester::class));
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

function payStore(): string
{
    return Fx::storeId('sa');
}

function payTester(): FakeKeyTester
{
    return app(FakeKeyTester::class);
}

function saveKey(string $store, string $mode = 'TEST', string $key = 'sk_test_key_ABC123', string $expiresOn = '2027-12-31'): void
{
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, $mode, $key, 'whsec_secret_1', $expiresOn));
}

/**
 * @return array{bool, ?PaymentMode, ?OnlinePaymentReason}
 */
function availability(string $store, PaymentAudience $audience = PaymentAudience::Individual): array
{
    $answer = app(PaymentsApi::class)->availability(StoreId::fromString($store), $audience);

    return [$answer->available, $answer->mode, $answer->reason];
}

describe('the keys (§1.2)', function () {
    it('tests a key at the mode\'s fixed address in the store\'s currency, then sets the gateway up switched off', function () {
        saveKey(payStore(), 'LIVE');

        expect(payTester()->calls)->toBe([['https://api-sa.myfatoorah.com', 'sk_test_key_ABC123', 'SAR']]);
        $view = app(ViewGatewayHandler::class)->handle(new ViewGateway(payStore()));
        expect($view->setUp)->toBeTrue()
            ->and($view->active)->toBeFalse()
            ->and($view->mode)->toBe('LIVE')
            ->and($view->liveKey['last6'] ?? null)->toBe('ABC123')
            ->and($view->liveKey['expiresOn'] ?? null)->toBe('2027-12-31')
            ->and($view->testKey)->toBeNull()
            ->and(Fx::audits('payments.gateway.set_up', payStore()))->toBe(1)
            ->and(Fx::audits('payments.gateway.key_changed', payStore()))->toBe(1);
    });

    it('tests the Egyptian store\'s live key at MyFatoorah Egypt, and every test key at the test address', function () {
        $eg = Fx::storeId('eg');
        saveKey($eg, 'LIVE');
        saveKey($eg, 'TEST');

        expect(payTester()->calls)->toBe([
            ['https://api-eg.myfatoorah.com', 'sk_test_key_ABC123', 'EGP'],
            ['https://apitest.myfatoorah.com', 'sk_test_key_ABC123', 'EGP'],
        ]);
    });

    it('keeps the key and the secret encrypted, and never writes them, in clear, to the audit log', function () {
        saveKey(payStore(), 'TEST', 'sk_never_in_clear_XYZ789');

        $row = DB::table('payments.gateway_keys')->sole();
        expect($row->key_encrypted)->not->toContain('sk_never_in_clear')
            ->and(app(Encrypter::class)->decryptString($row->key_encrypted))->toBe('sk_never_in_clear_XYZ789')
            ->and(app(Encrypter::class)->decryptString($row->secret_encrypted))->toBe('whsec_secret_1')
            ->and($row->key_last6)->toBe('XYZ789');

        $audits = DB::table('platform.audit_entries')->where('action', 'like', 'payments.%')->pluck('changes')->implode(' ');
        expect($audits)->not->toContain('sk_never_in_clear')
            ->and($audits)->not->toContain('whsec_secret_1')
            ->and($audits)->toContain('replaced')
            ->and($audits)->toContain('XYZ789');
    });

    it('retires the mode\'s earlier key, kept for its attempts, when a new one is saved', function () {
        saveKey(payStore(), 'TEST', 'sk_first_key_111111');
        saveKey(payStore(), 'TEST', 'sk_second_key_222222');

        $keys = DB::table('payments.gateway_keys')->orderBy('set_at')->orderBy('id')->get();
        expect($keys)->toHaveCount(2)
            ->and($keys->where('current', true)->pluck('key_last6')->all())->toBe(['222222'])
            ->and($keys->where('current', false)->sole()->retired_at)->not->toBeNull()
            ->and(Fx::audits('payments.gateway.set_up'))->toBe(1)
            ->and(Fx::audits('payments.gateway.key_changed'))->toBe(2);
    });

    it('saves nothing when the key\'s test fails, and names the missing permission', function () {
        payTester()->fails = new KeyPermissionMissing('the sessions');

        expect(fn () => saveKey(payStore()))->toThrow(KeyPermissionMissing::class);
        expect(DB::table('payments.gateways')->count())->toBe(0)
            ->and(DB::table('payments.gateway_keys')->count())->toBe(0);
    });

    it('refuses a store outside Saudi Arabia and Egypt before any call', function () {
        expect(fn () => saveKey(Fx::storeId('ae')))->toThrow(GatewayNotOffered::class);
        expect(payTester()->calls)->toBe([]);
    });

    it('refuses a mode, a key, a secret or an expiry date it cannot take, before any call', function (string $mode, string $key, string $secret, string $expires, string $field) {
        try {
            app(ChangeKeysHandler::class)->handle(new ChangeKeys(payStore(), $mode, $key, $secret, $expires));

            throw new LogicException('Expected a refusal');
        } catch (InvalidPaymentsInput $e) {
            expect($e->field)->toBe($field);
        }

        expect(payTester()->calls)->toBe([]);
    })->with([
        'mode' => ['DEMO', 'sk_key_123456', 'secret', '2027-01-01', 'mode'],
        'short key' => ['TEST', 'abc', 'secret', '2027-01-01', 'key'],
        'key with a space' => ['TEST', 'sk_key 123456', 'secret', '2027-01-01', 'key'],
        'key with an invisible mark' => ['TEST', "sk_key_123456\u{200F}", 'secret', '2027-01-01', 'key'],
        'empty secret' => ['TEST', 'sk_key_123456', '   ', '2027-01-01', 'secret'],
        'not a date' => ['TEST', 'sk_key_123456', 'secret_123', '31/12/2027', 'expires_on'],
        'no such day' => ['TEST', 'sk_key_123456', 'secret_123', '2027-02-30', 'expires_on'],
        'past' => ['TEST', 'sk_key_123456', 'secret_123', '2020-01-01', 'expires_on'],
    ]);

    it('takes a key that expires today, in the store\'s own day', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 22:30', 'UTC')); // 01:30 on 2 November in Riyadh

        saveKey(payStore(), 'TEST', expiresOn: '2026-11-02');
        expect(fn () => saveKey(payStore(), 'TEST', expiresOn: '2026-11-01'))->toThrow(InvalidPaymentsInput::class);
    });

    it('is a Super Admin\'s only, in the store', function () {
        // The permission is reserved: no role can hold it, so an admin with every other one of Payments' is refused.
        Fx::actAsAdmin(['sa'], [PaymentsPermissions::VIEW, PaymentsPermissions::BANK_ACCOUNT_UPDATE]);

        expect(fn () => saveKey(payStore()))->toThrow(Unauthorized::class)
            ->and(fn () => app(ViewGatewayHandler::class)->handle(new ViewGateway(payStore())))->toThrow(Unauthorized::class);
        expect(payTester()->calls)->toBe([]);
    });
});

describe('test or live, on or off (§1.2; gap 8.2)', function () {
    it('switches only a gateway that is set up', function () {
        expect(fn () => app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true)))->toThrow(GatewayNotSetUp::class)
            ->and(fn () => app(SwitchModeHandler::class)->handle(new SwitchMode(payStore(), 'LIVE')))->toThrow(GatewayNotSetUp::class);
    });

    it('switches to a mode only once that mode has its own key', function () {
        saveKey(payStore(), 'TEST');

        expect(fn () => app(SwitchModeHandler::class)->handle(new SwitchMode(payStore(), 'LIVE')))->toThrow(NoGatewayKey::class);

        saveKey(payStore(), 'LIVE');
        app(SwitchModeHandler::class)->handle(new SwitchMode(payStore(), 'live'));

        expect(app(ViewGatewayHandler::class)->handle(new ViewGateway(payStore()))->mode)->toBe('LIVE')
            ->and(Fx::audits('payments.gateway.mode_switched', payStore()))->toBe(1);

        // Switched to where it is: nothing changes, nothing is audited.
        app(SwitchModeHandler::class)->handle(new SwitchMode(payStore(), 'LIVE'));
        expect(Fx::audits('payments.gateway.mode_switched', payStore()))->toBe(1);
    });

    it('never switches on a gateway whose mode has no key', function () {
        // No command leaves a gateway without a key for its mode; a row written another way is still refused.
        DB::table('payments.gateways')->insert(['store_id' => strtolower(payStore()), 'gateway' => 'MYFATOORAH', 'active' => false, 'mode' => 'LIVE', 'created_by' => Fx::staff(), 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true)))->toThrow(NoGatewayKey::class);
    });

    it('switches on, and off, and audits each once', function () {
        saveKey(payStore(), 'TEST');
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true));

        expect(availability(payStore()))->toBe([true, PaymentMode::Test, null])
            ->and(Fx::audits('payments.gateway.switched_on', payStore()))->toBe(1);

        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), false));
        expect(availability(payStore()))->toBe([false, null, OnlinePaymentReason::GatewayOff])
            ->and(Fx::audits('payments.gateway.switched_off', payStore()))->toBe(1);
    });

    it('is a Super Admin\'s only', function () {
        saveKey(payStore(), 'TEST');
        Fx::actAsAdmin(['sa'], [PaymentsPermissions::VIEW]);

        expect(fn () => app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true)))->toThrow(Unauthorized::class)
            ->and(fn () => app(SwitchModeHandler::class)->handle(new SwitchMode(payStore(), 'LIVE')))->toThrow(Unauthorized::class);
    });
});

describe('availability (§2.1)', function () {
    it('answers why a store takes no online payment', function () {
        expect(availability(payStore()))->toBe([false, null, OnlinePaymentReason::NoGateway])
            ->and(availability(payStore(), PaymentAudience::Company))->toBe([false, null, OnlinePaymentReason::Company]);

        saveKey(payStore(), 'TEST');
        expect(availability(payStore()))->toBe([false, null, OnlinePaymentReason::GatewayOff]);

        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true));
        expect(availability(payStore(), PaymentAudience::Company))->toBe([false, null, OnlinePaymentReason::Company]);
    });

    it('stops by itself while MyFatoorah refuses the current key, and comes back with a new one', function () {
        saveKey(payStore(), 'TEST');
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true));
        DB::table('payments.gateway_keys')->where('current', true)->update(['rejected_at' => now()]);

        expect(availability(payStore()))->toBe([false, PaymentMode::Test, OnlinePaymentReason::KeyRejected])
            ->and(app(ViewGatewayHandler::class)->handle(new ViewGateway(payStore()))->testKey['rejected'] ?? null)->toBeTrue();

        saveKey(payStore(), 'TEST', 'sk_new_key_999999');
        expect(availability(payStore()))->toBe([true, PaymentMode::Test, null]);
    });

    it('answers another store\'s gateway as its own', function () {
        saveKey(payStore(), 'TEST');
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(payStore(), true));

        expect(availability(Fx::storeId('eg')))->toBe([false, null, OnlinePaymentReason::NoGateway]);
    });
});

describe('the expiry warnings (§1.2; gap 8.7)', function () {
    /**
     * @return list<array<array-key, mixed>>
     */
    function warnings(): array
    {
        $warnings = [];

        foreach (DB::table('public.outbox_messages')->where('type', GatewayKeyExpiring::class)->orderBy('created_at')->pluck('payload') as $payload) {
            $warnings[] = array_intersect_key((array) json_decode((string) $payload, true), array_flip(['storeId', 'mode', 'daysLeft', 'expiresOn']));
        }

        return $warnings;
    }

    it('warns 30, 7 and 1 days before, each once, in the store\'s own day', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Riyadh'));
        saveKey(payStore(), 'LIVE', expiresOn: '2026-12-31');
        $send = fn (): int => app(KeyExpiryWarnings::class)->send();

        expect($send())->toBe(0);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 00:05', 'Asia/Riyadh'));
        expect($send())->toBe(1)->and($send())->toBe(0);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-24 00:05', 'Asia/Riyadh'));
        expect($send())->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-30 00:05', 'Asia/Riyadh'));
        expect($send())->toBe(1)->and($send())->toBe(0);

        // Past its date: no more warnings; payment goes on until MyFatoorah refuses the key (gap 8.6). Five
        // minutes into the store's day, still the day before in UTC, the date is a day behind.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 00:05', 'Asia/Riyadh'));
        expect($send())->toBe(0)
            ->and(app(ViewGatewayHandler::class)->handle(new ViewGateway(payStore()))->liveKey['daysLeft'] ?? null)->toBe(-1);

        $store = strtolower(payStore());
        expect(warnings())->toEqual([
            ['storeId' => $store, 'mode' => 'LIVE', 'daysLeft' => 30, 'expiresOn' => '2026-12-31'],
            ['storeId' => $store, 'mode' => 'LIVE', 'daysLeft' => 7, 'expiresOn' => '2026-12-31'],
            ['storeId' => $store, 'mode' => 'LIVE', 'daysLeft' => 1, 'expiresOn' => '2026-12-31'],
        ]);
    });

    it('warns a key saved with less time left of the nearest threshold only', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Riyadh'));
        saveKey(payStore(), 'TEST', expiresOn: '2026-11-05');

        expect(app(KeyExpiryWarnings::class)->send())->toBe(1)
            ->and(array_column(warnings(), 'daysLeft'))->toBe([7]);
    });

    it('starts again for a new key, and never warns of a retired one', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Riyadh'));
        saveKey(payStore(), 'TEST', expiresOn: '2026-11-20');
        app(KeyExpiryWarnings::class)->send();
        saveKey(payStore(), 'TEST', 'sk_renewed_key_555555', '2026-11-25');

        // The retired key is 7 days out, a threshold it was never warned of: still nothing for it.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13 09:00', 'Asia/Riyadh'));
        expect(app(KeyExpiryWarnings::class)->send())->toBe(1)
            ->and(warnings())->toEqual([
                ['storeId' => strtolower(payStore()), 'mode' => 'TEST', 'daysLeft' => 30, 'expiresOn' => '2026-11-20'],
                ['storeId' => strtolower(payStore()), 'mode' => 'TEST', 'daysLeft' => 30, 'expiresOn' => '2026-11-25'],
            ]);
    });

    it('sends nothing when another run, or a renewal, marked the key first', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Riyadh'));
        saveKey(payStore(), 'TEST', expiresOn: '2026-11-20');
        $key = DB::table('payments.gateway_keys')->sole();

        expect(app(Gateways::class)->markWarned((string) $key->id, 30))->toBeTrue()
            ->and(app(Gateways::class)->markWarned((string) $key->id, 30))->toBeFalse();

        DB::table('payments.gateway_keys')->update(['warned_days' => null, 'current' => false, 'retired_at' => now()]);
        expect(app(Gateways::class)->markWarned((string) $key->id, 30))->toBeFalse();
    });

    it('tells nothing when another run marks the key between this run\'s read and its mark', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Riyadh'));
        saveKey(payStore(), 'TEST', expiresOn: '2026-11-20');
        $keys = app(Gateways::class)->currentKeys();
        // The other run's mark lands after this run read the keys.
        DB::table('payments.gateway_keys')->update(['warned_days' => 30]);
        app()->instance(Gateways::class, new StaleKeys(app(Gateways::class), $keys));

        expect(app(KeyExpiryWarnings::class)->send())->toBe(0)
            ->and(warnings())->toBe([]);
    });

    it('counts a day that starts at 01:00 (daylight saving) as a whole day', function () {
        // Egypt's clocks went forward at midnight on 2025-04-25: that day began at 01:00 in Cairo.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-04-25 09:00', 'Africa/Cairo'));

        expect(KeyExpiryWarnings::daysLeft(new DateTimeImmutable('2025-05-02'), 'Africa/Cairo'))->toBe(7);
    });

    it('names the nearest threshold for each number of days left', function (int $daysLeft, ?int $due) {
        expect(KeyExpiryWarnings::due($daysLeft))->toBe($due);
    })->with([[31, null], [30, 30], [8, 30], [7, 7], [2, 7], [1, 1], [0, 1], [-1, null]]);
});

describe('the timers (§1.7; gap 8.3)', function () {
    it('keeps the hold limit longer than the window and the wait together', function () {
        $set = fn (string $key, int $value) => app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, 'sa', $value));

        $set(PaymentsSettings::WINDOW_MINUTES, 10);
        $set(PaymentsSettings::WAIT_MINUTES, 4);

        expect(fn () => $set(PaymentsSettings::WAIT_MINUTES, 5))->toThrow(HoldTooShort::class)
            ->and(fn () => $set(PaymentsSettings::HOLD_MINUTES, 14))->toThrow(HoldTooShort::class)
            ->and(fn () => $set(PaymentsSettings::WINDOW_MINUTES, 11))->toThrow(HoldTooShort::class);

        $set(PaymentsSettings::HOLD_MINUTES, 30);
        $set(PaymentsSettings::WINDOW_MINUTES, 20);
        expect(app(PlatformApi::class)->setting(PaymentsSettings::WINDOW_MINUTES, StoreId::fromString(payStore()))->int())->toBe(20);
    });

    it('reads the transfer check hours from the store\'s setting', function () {
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(PaymentsSettings::TRANSFER_CHECK_HOURS, 'sa', 48));

        expect(app(PaymentsApi::class)->transferCheckHours(StoreId::fromString(payStore())))->toBe(48)
            ->and(app(PaymentsApi::class)->transferCheckHours(StoreId::fromString(Fx::storeId('eg'))))->toBe(72);
    });
});
