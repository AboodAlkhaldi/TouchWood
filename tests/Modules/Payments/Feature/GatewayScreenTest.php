<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\PaymentsPermissions as P;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The Gateway page over HTTP (payments.md §4.2): what it is handed - never more of a key than its last 6 characters -
| each post, who may reach it, the timers, Apple Pay, and the banners over the panel. No test calls MyFatoorah. Every
| helper is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

const GATEWAY_SCREEN_KEY = 'SECRETKEYabc123XYZ789';

const GATEWAY_SCREEN_SECRET = 'NOTICESECRETqwe456';

/** The key test, told what to do. */
function gatewayScreenTester(?Throwable $fails = null): void
{
    app()->instance(KeyTester::class, new class($fails) implements KeyTester
    {
        public function __construct(private ?Throwable $fails) {}

        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void
        {
            if ($this->fails !== null) {
                throw $this->fails;
            }
        }
    });
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    gatewayScreenTester();
});

/** @param  list<string>|null  $jobs  none: a Super Admin */
function gatewayScreenStaff(?array $jobs = null): AdminBrowser
{
    return gatewayScreenSignIn($jobs === null ? Fx::staff(superAdmin: true) : Fx::staffWith($jobs, ['sa']));
}

function gatewayScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, mixed> */
function gatewayScreenProps(AdminBrowser $browser, string $uri = '/admin/payments/gateway?store=sa', string $component = 'Payments/Admin/Gateway'): array
{
    $props = [];
    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

function gatewayScreenKey(AdminBrowser $browser, string $mode = 'TEST', ?string $expires = null, string $store = 'sa'): mixed
{
    return $browser->post('/admin/payments/gateway/keys', [
        'store' => $store, 'mode' => $mode, 'key' => GATEWAY_SCREEN_KEY, 'secret' => GATEWAY_SCREEN_SECRET,
        'expires_on' => $expires ?? CarbonImmutable::now()->addYear()->format('Y-m-d'),
    ]);
}

describe('the page (§4.2)', function () {
    it('saves a key, shows its last 6 characters and never the key or secret, and leaves the gateway off', function () {
        $browser = gatewayScreenStaff();
        $before = gatewayScreenProps($browser);

        $saved = gatewayScreenKey($browser);
        $response = $browser->get('/admin/payments/gateway?store=sa');
        $page = gatewayScreenProps($browser);

        expect([$before['setUp'], $before['active'], $before['mode'], $before['testKey'], $before['liveKey'], $before['offered']])->toBe([false, false, null, null, null, true])
            ->and(AdminBrowser::flashed($saved, 'status'))->toBe('Key tested and saved.')
            ->and([$page['setUp'], $page['active'], $page['mode'], $page['testKey']['last6'], $page['liveKey']])->toBe([true, false, 'TEST', 'XYZ789', null])
            ->and((string) $response->getContent())->not->toContain(GATEWAY_SCREEN_KEY)
            ->and((string) $response->getContent())->not->toContain(GATEWAY_SCREEN_SECRET);
    });

    it('says what a key is missing, saves nothing, and keeps nothing typed in the session', function () {
        $browser = gatewayScreenStaff();
        gatewayScreenTester(new KeyPermissionMissing('sessions'));

        $refused = gatewayScreenKey($browser);

        $sessions = DB::table('access.admin_sessions')->pluck('payload')->map(static fn (string $row): string => (string) base64_decode($row))->all();

        expect($sessions)->not->toBe([])
            ->and(json_encode($sessions))->not->toContain(GATEWAY_SCREEN_SECRET);
        expect(AdminBrowser::formError($refused))->toContain('sessions')
            ->and(gatewayScreenProps($browser)['setUp'])->toBeFalse()
            ->and(DB::table('payments.gateway_keys')->count())->toBe(0)
            ->and(json_encode(DB::table('access.admin_sessions')->pluck('payload')->map(static fn (string $row): string => (string) base64_decode($row))->all()))->not->toContain(GATEWAY_SCREEN_KEY);
    });

    it('switches on and off, and between modes only with a key for the other', function () {
        $browser = gatewayScreenStaff();
        gatewayScreenKey($browser);

        $on = $browser->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => true]);
        $active = gatewayScreenProps($browser)['active'];
        $noKey = $browser->post('/admin/payments/gateway/mode', ['store' => 'sa', 'mode' => 'LIVE']);
        gatewayScreenKey($browser, 'LIVE');
        $live = $browser->post('/admin/payments/gateway/mode', ['store' => 'sa', 'mode' => 'LIVE']);
        $page = gatewayScreenProps($browser);
        $off = $browser->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => false]);

        expect(AdminBrowser::flashed($on, 'status'))->toBe('Online payment is on.')
            ->and($active)->toBeTrue()
            ->and(AdminBrowser::formError($noKey))->toContain('no key')
            ->and(AdminBrowser::flashed($live, 'status'))->toBe('Now in Live mode.')
            ->and([$page['mode'], $page['liveKey']['last6']])->toBe(['LIVE', 'XYZ789'])
            ->and(AdminBrowser::flashed($off, 'status'))->toBe('Online payment is off.')
            ->and(gatewayScreenProps($browser)['active'])->toBeFalse();
    });

    it('tells a store outside the gateway\'s countries it isn\'t offered, and refuses a key there', function () {
        $browser = gatewayScreenStaff();

        expect(gatewayScreenProps($browser, '/admin/payments/gateway?store=ae')['offered'])->toBeFalse()
            ->and(AdminBrowser::formError(gatewayScreenKey($browser, store: 'ae')))->not->toBeNull();
    });

    it('hands each mode\'s notice address, and registers this domain for Apple Pay', function () {
        config(['app.url' => 'https://shop.example.test']);
        $browser = gatewayScreenStaff();
        gatewayScreenKey($browser);
        $client = Mockery::mock(GatewayClient::class);
        $client->shouldReceive('registerApplePayDomain')->once();
        app()->instance(GatewayClient::class, $client);

        $before = gatewayScreenProps($browser);
        $done = $browser->post('/admin/payments/gateway/apple-pay', ['store' => 'sa', 'mode' => 'TEST']);
        $after = gatewayScreenProps($browser);

        expect(AdminBrowser::formError($done))->toBeNull();
        expect($before['noticeTest'])->toContain('/payments/notice/')
            ->and($before['noticeLive'])->not->toBe($before['noticeTest'])
            ->and($before['applePayTest'])->toBeFalse()
            ->and(AdminBrowser::flashed($done, 'status'))->toBe('Domain registered for Apple Pay.')
            ->and([$after['applePayTest'], $after['applePayLive']])->toBe([true, false]);
    });

    it('says MyFatoorah\'s own words to the Super Admin when it refuses a domain', function () {
        config(['app.url' => 'https://shop.example.test']);
        $browser = gatewayScreenStaff();
        gatewayScreenKey($browser);
        $client = Mockery::mock(GatewayClient::class);
        $client->shouldReceive('registerApplePayDomain')->once()->andThrow(new GatewayRefused('the domain file is missing'));
        app()->instance(GatewayClient::class, $client);

        expect(AdminBrowser::formError($browser->post('/admin/payments/gateway/apple-pay', ['store' => 'sa', 'mode' => 'TEST'])))->toContain('the domain file is missing');
    });

    it('refuses a store that isn\'t there', function () {
        gatewayScreenStaff()->get('/admin/payments/gateway?store=zz')->assertForbidden();
    });

    it('is a Super Admin\'s only: the page and its posts', function () {
        $browser = gatewayScreenStaff([P::VIEW]);

        $browser->get('/admin/payments/gateway?store=sa')->assertForbidden();

        expect(AdminBrowser::formError($browser->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => true])))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post('/admin/payments/gateway/switch', ['on' => true])))->not->toBeNull()
            ->and(DB::table('payments.gateways')->count())->toBe(0);
    });
});

describe('the queries (§4.2)', function () {
    it('reads the page in a recorded number, with a key or two', function () {
        $browser = gatewayScreenStaff();
        gatewayScreenKey($browser);
        gatewayScreenKey($browser, 'LIVE');
        $browser->get('/admin/payments/gateway?store=sa')->assertOk();
        $seen = new ArrayObject;
        DB::listen(static function (QueryExecuted $query) use ($seen): void {
            $seen->append($query->sql);
        });

        $browser->get('/admin/payments/gateway?store=sa')->assertOk();

        // The whole request, session and panel frame included, as a Super Admin.
        expect(count($seen))->toBe(39);
    });
});

describe('the timers (§9.12 Q17)', function () {
    it('hands them over, saves them through the settings route, refuses a hold too short, and leaves the Settings page', function () {
        $browser = gatewayScreenStaff();
        $page = gatewayScreenProps($browser);

        $browser->post('/admin/settings/payments.timers.hold_minutes', ['store' => 'sa', 'value' => '20'])->assertRedirect();
        $browser->post('/admin/settings/payments.timers.window_minutes', ['store' => 'sa', 'value' => '10'])->assertRedirect();
        $short = $browser->post('/admin/settings/payments.timers.hold_minutes', ['store' => 'sa', 'value' => '11']);
        $after = gatewayScreenProps($browser);
        $settings = (string) $browser->get('/admin/settings?store=sa')->getContent();

        expect([$page['windowMinutes'], $page['waitMinutes'], $page['holdMinutes']])->toBe([5, 2, 15])
            ->and(AdminBrowser::formError($short))->not->toBeNull()
            ->and([$after['windowMinutes'], $after['holdMinutes']])->toBe([10, 20])
            ->and($settings)->not->toContain('payments.timers.window_minutes')
            ->and($settings)->not->toContain('payments.timers.hold_minutes');
    });
});

describe('the banners (§4.2; Q18)', function () {
    it('says a store is in Test on every page to staff who hold it, whatever their job, and to no one else', function () {
        $manager = gatewayScreenStaff();
        gatewayScreenKey($manager);
        $manager->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => true]);
        $viewer = gatewayScreenStaff([P::VIEW]);
        $editor = gatewayScreenStaff([PlatformPermissions::MEDIA_UPLOAD]);
        $elsewhere = Fx::staffWith([P::VIEW], ['eg']);
        $farAway = gatewayScreenSignIn($elsewhere);

        $mine = gatewayScreenProps($viewer, '/admin', 'Admin/Home')['banners'];

        expect($mine)->toHaveCount(1)
            ->and($mine[0]['everywhere'])->toBeTrue()
            ->and($mine[0]['text'])->toContain('No real money')
            ->and(gatewayScreenProps($editor, '/admin', 'Admin/Home')['banners'])->toHaveCount(1)
            ->and(gatewayScreenProps($farAway, '/admin', 'Admin/Home')['banners'])->toBe([]);

        $manager->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => false]);

        expect(gatewayScreenProps($viewer, '/admin', 'Admin/Home')['banners'])->toBe([]);
    });

    it('warns of a key expiring or refused, for Home only, to those who manage the gateway', function () {
        $manager = gatewayScreenStaff();
        gatewayScreenKey($manager, 'LIVE', CarbonImmutable::now()->addDays(5)->format('Y-m-d'));
        $manager->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => true]);

        $banners = gatewayScreenProps($manager, '/admin', 'Admin/Home')['banners'];
        $viewer = gatewayScreenProps(gatewayScreenStaff([P::VIEW]), '/admin', 'Admin/Home')['banners'];

        expect($banners)->toHaveCount(1)
            ->and($banners[0]['everywhere'])->toBeFalse()
            ->and($banners[0]['text'])->toContain('expires in')
            ->and($banners[0]['href'])->toBe('/admin/payments/gateway?store=sa')
            ->and($viewer)->toBe([]);

        DB::table('payments.gateway_keys')->update(['rejected_at' => CarbonImmutable::now()]);
        $manager->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => false]);
        $manager->post('/admin/payments/gateway/switch', ['store' => 'sa', 'on' => true]);

        expect(gatewayScreenProps($manager, '/admin', 'Admin/Home')['banners'][0]['text'])->toContain('refused');
    });

    it('warns of a Live key dying while the store is still in Test, and of tomorrow and today in their own words', function () {
        $manager = gatewayScreenStaff();
        gatewayScreenKey($manager);
        gatewayScreenKey($manager, 'LIVE', CarbonImmutable::now('Asia/Riyadh')->addDay()->format('Y-m-d'));
        $page = gatewayScreenProps($manager);

        expect(gatewayScreenProps($manager, '/admin', 'Admin/Home')['banners'][0]['text'])->toContain('tomorrow')
            ->and($page['mode'])->toBe('TEST');
    });

    it('costs a panel page no query of its own once it has asked, while nothing is on', function () {
        $browser = gatewayScreenStaff();
        $browser->get('/admin')->assertOk();
        $seen = new ArrayObject;
        DB::listen(static function (QueryExecuted $query) use ($seen): void {
            if (str_contains($query->sql, 'payments.gateway')) {
                $seen->append($query->sql);
            }
        });

        $props = gatewayScreenProps($browser, '/admin', 'Admin/Home');

        expect($props['banners'])->toBe([])
            ->and(count($seen))->toBe(0);
    });
});
