<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Enums\PolicyKind;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\TermsChanged;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentFixtures;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The terms at checkout over HTTP (flags-2026-11.md S3.3, F16): the page names the store's Terms and Conditions and its
| Returns and Refunds policy, each a link to its page, the Returns left out while there is none and both null while
| the store has no terms; placing and Pay without the tick, or in a store without terms, are refused in the page's
| words; staff read on the order which version was ticked, linking to that version's text. And what the read costs.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    Sleep::fake();
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
});

/**
 * A customer with a cart in 'sa', whose terms are published, and a browser signed in as them; the gateway on when asked.
 *
 * @return array{0: AdminBrowser, 1: array{customer: string, address: string, variants: list<string>, product: string}, 2: string}
 */
function checkoutTermsScreensCustomer(bool $online = false): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        if ($online) {
            FakeMyFatoorah::install();
            Cx::asSuperAdmin(function (): void {
                app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'LIVE', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
                app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
            });
        }

        $ready = Cx::ready();
        $terms = Cx::terms();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));
    $email = (string) DB::table('access.customers')->where('id', $ready['customer'])->value('email');
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return [$browser, $ready, $terms];
}

/** As a Super Admin, the real ActorContext given back after. */
function checkoutTermsScreensAsAdmin(Closure $work): mixed
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        return Cx::asSuperAdmin($work);
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/** A staff member of 'sa' who may view orders, signed in. */
function checkoutTermsScreensStaff(): AdminBrowser
{
    $staffId = Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']);
    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, mixed> */
function checkoutTermsScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** The terms saved with new words, as their editor: their version 2. */
function checkoutTermsScreensReword(string $policyId): void
{
    checkoutTermsScreensAsAdmin(fn () => app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(
        $policyId, 'TERMS', [Fx::storeId('sa')], (int) DB::table('content.policies')->where('id', $policyId)->value('version'),
        'الشروط', 'Terms', 'الشروط-المعدلة', 'terms-reworded', 'نص آخر', 'Other words',
    ))));
}

function checkoutTermsScreensSlug(PolicyKind $kind, string $locale): string
{
    return app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('sa')), $kind, $locale)->slug ?? throw new LogicException('Not published.');
}

describe('S5 · The terms\' tick', function () {
    it('names the Terms and Conditions, with the Returns policy once there is one, each a link to its page, in each language', function () {
        [$browser] = checkoutTermsScreensCustomer();

        $termsOnly = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        checkoutTermsScreensAsAdmin(fn (): string => ContentFixtures::publishedPolicy('RETURNS'));
        $both = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $arabic = checkoutTermsScreensProps($browser, '/sa/ar/checkout', 'Sales/Storefront/Checkout');

        $shown = Cx::shown();

        expect($termsOnly['terms']['url'])->toEndWith('/sa/en/policies/'.checkoutTermsScreensSlug(PolicyKind::Terms, 'en'))
            // Which policy and version the page shows, for it to send back with the tick (owner, 2026-11-20).
            ->and([$termsOnly['terms']['policyId'], $termsOnly['terms']['version']])->toBe([$shown->termsId, $shown->termsVersion])
            ->and($termsOnly['returns'])->toBeNull()
            ->and($both['returns']['url'])->toEndWith('/sa/en/policies/'.checkoutTermsScreensSlug(PolicyKind::Returns, 'en'))
            ->and([$both['returns']['policyId'], $both['returns']['version']])->toBe([$shown->returnsId, 1])
            ->and(rawurldecode((string) $arabic['terms']['url']))->toEndWith('/sa/ar/policies/'.checkoutTermsScreensSlug(PolicyKind::Terms, 'ar'))
            ->and(rawurldecode((string) $arabic['returns']['url']))->toEndWith('/sa/ar/policies/'.checkoutTermsScreensSlug(PolicyKind::Returns, 'ar'));
    });

    it('has no terms to name in a store without them, and refuses its orders in the page\'s words', function () {
        [$browser, , $terms] = checkoutTermsScreensCustomer();
        checkoutTermsScreensAsAdmin(fn () => app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($terms, (int) DB::table('content.policies')->where('id', $terms)->value('version'))));

        $checkout = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $refused = $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId'], 'agreed' => true]);

        expect([$checkout['terms'], $checkout['returns']])->toBe([null, null])
            ->and($checkout['quoteId'])->not->toBeNull()
            ->and(AdminBrowser::formError($refused))->toBe('Orders can\'t be placed until this store\'s terms are published.')
            ->and(DB::table('sales.orders')->count())->toBe(0);
    });

    it('refuses placing without the tick, whatever the page sent, in the page\'s words', function (array $sent) {
        [$browser] = checkoutTermsScreensCustomer();
        $checkout = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');

        $refused = $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId'], ...Cx::shownSent(), ...$sent]);

        expect(AdminBrowser::formError($refused))->toBe('Tick the box to agree to the terms.')
            ->and(DB::table('sales.orders')->count())->toBe(0);
    })->with([
        'no tick sent' => [[]],
        'unticked' => [['agreed' => false]],
        'a word, not a tick' => [['agreed' => 'yes']],
    ]);

    it('refuses Pay without the tick with the problem in the page\'s words, and no try', function () {
        [$browser] = checkoutTermsScreensCustomer(online: true);
        $checkout = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $json = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        $session = (string) $browser->post('/sa/en/checkout/payment/session', ['quote' => $checkout['quoteId']], $json)->assertOk()->json('sessionId');

        $refused = $browser->post('/sa/en/checkout/payment/pay', ['quote' => $checkout['quoteId'], 'session' => $session, ...Cx::shownSent()], $json);

        expect($checkout['way'])->toBe('ONLINE')
            ->and($refused->status())->toBe(422)
            ->and((string) $refused->json('type'))->toEndWith('terms_not_accepted')
            ->and($refused->json('detail'))->toBe('Tick the box to agree to the terms.')
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0);
    });

    it('refuses placing under terms changed since the page showed them, and the page shows the new ones (owner, 2026-11-20)', function () {
        [$browser, , $terms] = checkoutTermsScreensCustomer();
        $checkout = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        checkoutTermsScreensReword($terms);

        $refused = $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId'], 'agreed' => true, 'terms_id' => $checkout['terms']['policyId'], 'terms_version' => $checkout['terms']['version']]);
        $again = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');

        expect(AdminBrowser::formError($refused))->toBe('The Terms were updated. Read them and tick again.')
            ->and(DB::table('sales.orders')->count())->toBe(0)
            ->and([$checkout['terms']['version'], $again['terms']['version']])->toBe([1, 2]);

        app()->setLocale('ar');
        expect(FormErrors::message(new TermsChanged))->toBe('حُدِّثت الشروط. اقرأها ثم ضع علامة في المربع من جديد.');
    });

    it('refuses Pay under terms changed since the page showed them as a conflict, in the page\'s words, and no try', function () {
        [$browser, , $terms] = checkoutTermsScreensCustomer(online: true);
        $checkout = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $json = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        $session = (string) $browser->post('/sa/en/checkout/payment/session', ['quote' => $checkout['quoteId']], $json)->assertOk()->json('sessionId');
        checkoutTermsScreensReword($terms);

        $refused = $browser->post('/sa/en/checkout/payment/pay', ['quote' => $checkout['quoteId'], 'session' => $session, 'agreed' => true, 'terms_id' => $checkout['terms']['policyId'], 'terms_version' => $checkout['terms']['version']], $json);

        expect($refused->status())->toBe(409)
            ->and((string) $refused->json('type'))->toEndWith('terms_changed')
            ->and($refused->json('detail'))->toBe('The Terms were updated. Read them and tick again.')
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0);
    });

    it('reads the store\'s policies once a checkout, two queries warm (Content\'s cache)', function () {
        [$browser] = checkoutTermsScreensCustomer();
        $browser->get('/sa/en/checkout')->assertOk();
        /** @var ArrayObject<int, string> $read */
        $read = new ArrayObject;

        DB::listen(function (QueryExecuted $query) use ($read): void {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (str_ends_with(str_replace(chr(92), '/', $frame['file'] ?? ''), '/Sales/Application/Checkout/CheckoutTerms.php')) {
                    $read[] = $query->sql;

                    return;
                }
            }
        });

        $browser->get('/sa/en/checkout')->assertOk();

        expect(count($read))->toBe(2);
    });
});

describe('P2 · The terms on the order (F16)', function () {
    it('shows staff the version ticked and when, linking to that version\'s text in their language', function () {
        [$browser, $ready, $terms] = checkoutTermsScreensCustomer();
        $checkout = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId'], 'agreed' => true, ...Cx::shownSent()]);
        $order = DB::table('sales.orders')->where('customer_id', $ready['customer'])->sole();
        $staff = checkoutTermsScreensStaff();

        $page = checkoutTermsScreensProps($staff, "/admin/orders/{$order->id}", 'Sales/Admin/Orders/Show');

        expect([$order->terms_policy_id, (int) $order->terms_version])->toBe([$terms, 1])
            ->and($page['terms']['version'])->toBe(1)
            ->and($page['terms']['acceptedAt'])->toBe(CarbonImmutable::parse((string) $order->terms_accepted_at)->utc()->format('Y-m-d\TH:i:s.vP'))
            ->and($page['terms']['url'])->toEndWith('/sa/en/policies/'.checkoutTermsScreensSlug(PolicyKind::Terms, 'en')."?version=1&policy={$terms}")
            ->and([$page['terms']['returnsVersion'], $page['terms']['returnsUrl']])->toBe([null, null]);
    });

    it('keeps the line and its link once the policy is no longer published (its page then opens labelled as old, owner 2026-11-20), and shows nothing on an order from before', function () {
        [$browser, $ready, $terms] = checkoutTermsScreensCustomer();
        $checkout = checkoutTermsScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId'], 'agreed' => true, ...Cx::shownSent()]);
        $order = (string) DB::table('sales.orders')->where('customer_id', $ready['customer'])->value('id');
        $slug = checkoutTermsScreensSlug(PolicyKind::Terms, 'en');
        checkoutTermsScreensAsAdmin(fn () => app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($terms, (int) DB::table('content.policies')->where('id', $terms)->value('version'))));
        $staff = checkoutTermsScreensStaff();

        $unpublished = checkoutTermsScreensProps($staff, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show');
        DB::table('sales.orders')->where('id', $order)->update(['terms_policy_id' => null, 'terms_version' => null, 'terms_accepted_at' => null]);
        $before = checkoutTermsScreensProps($staff, "/admin/orders/{$order}", 'Sales/Admin/Orders/Show');

        expect($unpublished['terms']['version'])->toBe(1)
            ->and($unpublished['terms']['url'])->toEndWith("/sa/en/policies/{$slug}?version=1&policy={$terms}")
            ->and($before['terms'])->toBeNull();
    });
});
