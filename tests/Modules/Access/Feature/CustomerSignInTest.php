<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Access\Presentation\Http\Middleware\IdentifyCustomer;
use Modules\Access\Presentation\Http\Middleware\UseStorefrontSession;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Access\Public\Events\GuestBecameCustomer;
use Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Illuminate\Support\defer;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

const SHOP_PASSWORD = 'a long enough password';

beforeEach(function () {
    // Sessions live in the database, as in production: the storefront session is read again on
    // every request (phpunit.xml's in-memory driver keeps nothing between them here).
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();

    // Who a storefront request acts as, to look at from a test: the pages come with the frontend stage.
    // The same middleware every storefront page has, so the session behaves as it does in the shop.
    Route::middleware([UseStorefrontSession::ALIAS, 'web', 'store', IdentifyCustomer::ALIAS])
        ->get('{store}/{locale}/_who', fn (ActorContext $actors) => response()->json([
            'type' => $actors->current()->type->value,
            'id' => $actors->current()->id,
        ]));
});

/**
 * Who this browser is on the storefront: a customer's id, or null for a guest. Anything else fails
 * the test — a web request never acts as the system.
 */
function shopWho(AdminBrowser $browser): ?string
{
    $who = $browser->get('/sa/en/_who')->json();
    $who = is_array($who) ? $who : [];
    $type = $who['type'] ?? null;

    if ($type === 'GUEST') {
        return null;
    }

    if ($type !== 'CUSTOMER' || ! is_string($who['id'] ?? null)) {
        throw new LogicException('A storefront request acted as '.var_export($type, true).'.');
    }

    return $who['id'];
}

function shopEmail(string $customerId): string
{
    return (string) DB::table('access.customers')->where('id', $customerId)->value('email');
}

/**
 * @param  array<string, mixed>  $extra
 * @return TestResponse<Response>
 */
function shopSignIn(AdminBrowser $browser, string $customerId, string $password = SHOP_PASSWORD, array $extra = []): TestResponse
{
    return $browser->post('/sa/en/account/sign-in', ['email' => shopEmail($customerId), 'password' => $password, ...$extra]);
}

/**
 * @return TestResponse<Response>
 */
function shopRegister(AdminBrowser $browser, string $email = 'new@example.test', string $store = 'sa'): TestResponse
{
    return $browser->post("/{$store}/en/account/register", [
        'email' => $email,
        'password' => SHOP_PASSWORD,
        'first_name' => 'Noura',
        'last_name' => 'Saleh',
        'account_type' => 'individual',
        'locale' => 'en',
        'terms' => '1',
    ]);
}

describe('registering and signing in (spec §1.2, §1.8)', function () {
    it('signs a new customer in at once, in the store they registered in', function () {
        $browser = new AdminBrowser;

        shopRegister($browser)->assertRedirect('/sa/en');
        $customerId = (string) DB::table('access.customers')->where('email', 'new@example.test')->value('id');

        expect(shopWho($browser))->toBe($customerId)
            ->and(RecordingSecurityMessages::installed()->emailVerifications)->toHaveCount(1);
    });

    it('signs in with the email and password, with a new session id', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        $before = $browser->cookie((string) config('session.cookie'));

        shopSignIn($browser, $customerId)->assertRedirect('/sa/en');

        expect(shopWho($browser))->toBe($customerId)
            ->and($browser->cookie((string) config('session.cookie')))->not->toBe($before);
    });

    it('answers a wrong email and a wrong password the same way', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;

        $wrongPassword = shopSignIn($browser, $customerId, 'not the password');
        $unknownEmail = $browser->post('/sa/en/account/sign-in', ['email' => 'nobody@example.test', 'password' => SHOP_PASSWORD]);

        expect(AdminBrowser::formError($wrongPassword))->toBe((string) __('access::errors.invalid_credentials.detail'))
            ->and(AdminBrowser::formError($unknownEmail))->toBe((string) __('access::errors.invalid_credentials.detail'))
            ->and(shopWho($browser))->toBeNull();
    });

    it('tells a blocked customer plainly, but only after the right password', function () {
        $customerId = Fx::customer();
        DB::table('access.customers')->where('id', $customerId)->update(['status' => CustomerStatus::Blocked->value]);
        $browser = new AdminBrowser;

        expect(AdminBrowser::formError(shopSignIn($browser, $customerId, 'not the password')))->toBe((string) __('access::errors.invalid_credentials.detail'))
            ->and(AdminBrowser::formError(shopSignIn($browser, $customerId)))->toBe((string) __('access::errors.customer_blocked.detail'))
            ->and(shopWho($browser))->toBeNull();
    });

    it('locks an account after 5 wrong passwords, and an address after 10', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser('10.0.0.70');

        foreach (range(1, 5) as $try) {
            shopSignIn($browser, $customerId, 'wrong '.$try);
        }

        expect(AdminBrowser::formError(shopSignIn($browser, $customerId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]));

        // The address keeps counting across accounts, on its own key.
        foreach (range(1, 5) as $try) {
            $browser->post('/sa/en/account/sign-in', ['email' => "someone{$try}@example.test", 'password' => 'guess']);
        }

        expect(AdminBrowser::formError((new AdminBrowser('10.0.0.70'))->post('/sa/en/account/sign-in', ['email' => 'other@example.test', 'password' => 'guess'])))
            ->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]));
    });

    it('starts the count again after the right password', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser('10.0.0.74');

        foreach (range(1, 4) as $try) {
            shopSignIn($browser, $customerId, 'wrong '.$try);
        }

        shopSignIn($browser, $customerId)->assertRedirect('/sa/en');

        // The same browser, signed out again, from the same address.
        $later = new AdminBrowser('10.0.0.74');

        foreach (range(1, 4) as $try) {
            shopSignIn($later, $customerId, 'wrong again '.$try);
        }

        // Eight wrong passwords in all, never five in a row: the account is not locked.
        shopSignIn($later, $customerId)->assertRedirect('/sa/en');
        expect(shopWho($later))->toBe($customerId);
    });

    it('makes the wait start at the last wrong password, not the first', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser('10.0.0.75');

        foreach (range(1, 4) as $try) {
            shopSignIn($browser, $customerId, 'wrong '.$try);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(14));
        shopSignIn($browser, $customerId, 'wrong 5');

        // A minute later the first four would have been forgotten; the lock still has its 15.
        expect(AdminBrowser::formError(shopSignIn($browser, $customerId)))
            ->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]));
    });

    it('keeps the admin lockout apart from the shop one', function () {
        $customerId = Fx::customer();
        $staffId = Fx::staff();
        $browser = new AdminBrowser('10.0.0.71');

        foreach (range(1, 10) as $try) {
            $browser->post('/sa/en/account/sign-in', ['email' => "shopper{$try}@example.test", 'password' => 'guess']);
        }

        // The same address may still reach the admin panel: staff count on their own keys.
        $admin = $browser->post('/admin/sign-in', ['email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'), 'password' => 'a long enough password']);

        expect($admin->headers->get('Location'))->toContain('/admin/sign-in/code')
            ->and($customerId)->not->toBeEmpty();
    });

    it('lands the customer in the store they last used', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;

        $browser->post('/ae/en/account/sign-in', ['email' => shopEmail($customerId), 'password' => SHOP_PASSWORD])->assertRedirect('/ae/en');

        expect(DB::table('access.customers')->where('id', $customerId)->value('last_store_id'))->toBe(Fx::storeId('ae'))
            ->and(DB::table('access.customers')->where('id', $customerId)->value('home_store_id'))->toBe(Fx::storeId('sa'));
    });

    it('tells Sales that this guest became that customer', function (string $path, string $how) {
        Event::fake([GuestBecameCustomer::class]);
        $guestId = strtolower((string) Str::ulid());
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        $browser->setCookie((string) config('access.storefront.guest_cookie'), $guestId);

        $path === 'register'
            ? shopRegister($browser, 'guest@example.test')
            : shopSignIn($browser, $customerId);

        Event::assertDispatched(GuestBecameCustomer::class, fn (GuestBecameCustomer $event): bool => $event->guestId === $guestId && $event->how === $how);
    })->with([
        'registering' => ['register', GuestBecameCustomer::REGISTERED],
        'signing in' => ['sign-in', GuestBecameCustomer::SIGNED_IN],
    ]);
});

describe('the storefront session (spec §1.8)', function () {
    it('ends after 2 hours idle without "remember me"', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        shopSignIn($browser, $customerId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(119));
        expect(shopWho($browser))->toBe($customerId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(121));
        expect(shopWho($browser))->toBeNull();
    });

    it('keeps them signed in for 30 days with "remember me", however quiet', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        shopSignIn($browser, $customerId, extra: ['remember' => '1']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(29));
        expect(shopWho($browser))->toBe($customerId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
        expect(shopWho($browser))->toBeNull();
    });

    it('ends at once when the account is blocked', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        shopSignIn($browser, $customerId);

        DB::table('access.customers')->where('id', $customerId)->update(['status' => CustomerStatus::Blocked->value]);

        expect(shopWho($browser))->toBeNull();
    });

    it('is separate from the admin session', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        shopSignIn($browser, $customerId);

        expect($browser->cookie('touchwood_admin_session'))->toBeNull()
            ->and($browser->cookie((string) config('session.cookie')))->not->toBeNull()
            ->and(shopWho($browser))->toBe($customerId);
    });

    it('signs out of this browser only', function () {
        $customerId = Fx::customer();
        $here = new AdminBrowser;
        $there = new AdminBrowser('10.0.0.72');
        shopSignIn($here, $customerId);
        shopSignIn($there, $customerId);

        $here->post('/sa/en/account/sign-out')->assertRedirect('/sa/en');

        expect(shopWho($here))->toBeNull()
            ->and(shopWho($there))->toBe($customerId);
    });

    it('sends someone who is not signed in back to the store', function () {
        (new AdminBrowser)->post('/sa/en/account/sign-out')->assertRedirect('/sa/en');
    });
});

describe('a customer password (spec §1.8)', function () {
    it('is reset by an email link that works once, and ends every session', function () {
        $customerId = Fx::customer();
        $signedIn = new AdminBrowser;
        shopSignIn($signedIn, $customerId);
        expect(shopWho($signedIn))->toBe($customerId);

        $browser = new AdminBrowser;
        $browser->post('/sa/en/account/password/forgot', ['email' => shopEmail($customerId)])->assertRedirect();
        defer()->invoke();
        $link = RecordingSecurityMessages::installed()->passwordResets[0]['link'] ?? '';
        $token = basename($link);

        expect($link)->toStartWith(config('app.url').'/sa/en/account/password/reset/');

        $browser->post("/sa/en/account/password/reset/{$token}", ['password' => 'a brand new long password'])->assertRedirect('/sa/en');

        expect(shopWho($signedIn))->toBeNull()
            ->and(AdminBrowser::formError($browser->post("/sa/en/account/password/reset/{$token}", ['password' => 'another long password'])))
            ->toBe((string) __('access::errors.invalid_or_expired_link.detail'));

        shopSignIn($browser, $customerId, 'a brand new long password')->assertRedirect('/sa/en');
        expect(shopWho($browser))->toBe($customerId);
    });

    it('answers the same whether or not the email has an account, and sends after the answer', function () {
        $browser = new AdminBrowser;
        $known = $browser->post('/sa/en/account/password/forgot', ['email' => shopEmail(Fx::customer())]);
        $unknown = $browser->post('/sa/en/account/password/forgot', ['email' => 'nobody@example.test']);

        expect(AdminBrowser::flashed($known, 'status'))->toBe(AdminBrowser::flashed($unknown, 'status'))
            ->and(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(1);
    });

    it('sends at most 3 links an hour to one account', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;

        foreach (range(1, 4) as $asked) {
            $browser->post('/sa/en/account/password/forgot', ['email' => shopEmail($customerId)])->assertRedirect();
        }

        defer()->invoke();

        expect(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(3);
    });

    it('changes with the current one, keeping this session and ending the others', function () {
        $customerId = Fx::customer();
        $here = new AdminBrowser;
        $there = new AdminBrowser('10.0.0.73');
        shopSignIn($here, $customerId);
        shopSignIn($there, $customerId);

        $here->post('/sa/en/account/password', ['current_password' => SHOP_PASSWORD, 'password' => 'a brand new long password'])->assertRedirect();

        expect(shopWho($here))->toBe($customerId)
            ->and(shopWho($there))->toBeNull()
            ->and(Fx::audits('access.customer.password_changed', $customerId))->toBe(1);
    });

    it('counts a wrong current password like a wrong password at sign-in', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        shopSignIn($browser, $customerId);

        foreach (range(1, 4) as $try) {
            $browser->post('/sa/en/account/password', ['current_password' => 'guess '.$try, 'password' => 'a brand new long password']);
        }

        // The fifth locks, and the wait is the full 15 minutes from it, not what the first left.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(14));
        $browser->post('/sa/en/account/password', ['current_password' => 'guess 5', 'password' => 'a brand new long password']);

        expect(AdminBrowser::formError($browser->post('/sa/en/account/password', ['current_password' => SHOP_PASSWORD, 'password' => 'a brand new long password'])))
            ->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]))
            ->and(DB::table('access.customers')->where('id', $customerId)->value('session_version'))->toBe(0);
    });
});

describe('resending the verification link (spec §1.2)', function () {
    it('sends a new link to the customer signed in, at most 3 an hour', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        shopSignIn($browser, $customerId);

        foreach (range(1, 4) as $asked) {
            $browser->post('/sa/en/account/verify-email/resend');
        }

        // One from registering, three asked for.
        expect(RecordingSecurityMessages::installed()->emailVerifications)->toHaveCount(4);
    });

    it('is only for someone signed in, and does nothing once the address is verified', function () {
        $customerId = Fx::customer();
        (new AdminBrowser)->post('/sa/en/account/verify-email/resend')->assertRedirect('/sa/en');

        DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => now()]);
        $browser = new AdminBrowser;
        shopSignIn($browser, $customerId);
        $browser->post('/sa/en/account/verify-email/resend');

        expect(RecordingSecurityMessages::installed()->emailVerifications)->toHaveCount(1);
    });
});
