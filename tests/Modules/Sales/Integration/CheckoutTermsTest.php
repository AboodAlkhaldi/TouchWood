<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
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
use Modules\Sales\Application\Checkout\ShownTerms;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPayment;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPaymentHandler;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPayment;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPaymentHandler;
use Modules\Sales\Application\Command\PayOnline\PayOnline;
use Modules\Sales\Application\Command\PayOnline\PayOnlineHandler;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrder;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrderHandler;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\TermsChanged;
use Modules\Sales\Domain\Exception\TermsMissing;
use Modules\Sales\Domain\Exception\TermsNotAccepted;
use Modules\Sales\Domain\Model\AcceptedTerms;
use Modules\Sales\Domain\Model\PlacedOrder;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The terms at checkout (flags-2026-11.md S3.3, F16), by both ways to order - placing (`PlaceOrder`) and paying online
| (`PayOnline`, whose try makes the order once paid): refused without the tick whatever the page sent, refused in a
| store without published terms (`TermsMissing`), and the order keeps the Terms and Conditions' policy, the version
| ticked and when - the Returns and Refunds policy's too when the store has one. Staff read it on the order.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    RateLimiter::clear('payments-checks');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/** @return array{customer: string, address: string, variants: list<string>, product: string, terms: string} */
function checkoutTermsReady(): array
{
    $ready = Cx::ready();

    return [...$ready, 'terms' => Cx::terms()];
}

/** The store's Terms and Conditions taken down, as their editor: the store has none published. */
function checkoutTermsUnpublish(string $policyId): void
{
    Cx::asSuperAdmin(fn () => app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($policyId, (int) DB::table('content.policies')->where('id', $policyId)->value('version'))));
}

/** The policy saved with new words: its next version (F14). */
function checkoutTermsReword(string $policyId, string $kind = 'TERMS'): void
{
    Cx::asSuperAdmin(fn () => app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(
        $policyId, $kind, [Fx::storeId('sa')], (int) DB::table('content.policies')->where('id', $policyId)->value('version'),
        'الشروط', 'Terms', 'الشروط-المعدلة', 'terms-reworded', 'نص آخر', 'Other words',
    ))));
}

/** Placed as the page sends it: the tick, and the terms it showed - the store's now unless told. */
function checkoutTermsPlace(string $quoteId, bool $agreed, ?ShownTerms $shown = null): PlacedOrder
{
    return app(PlaceOrderHandler::class)->handle(new PlaceOrder(Fx::storeId('sa'), $quoteId, $agreed, $shown ?? Cx::shown()));
}

/** @return array{policy: ?string, version: ?int, at: ?string, returns: ?string, returnsVersion: ?int} */
function checkoutTermsOf(?string $orderId = null): array
{
    $row = DB::table('sales.orders')->when($orderId !== null, fn ($query) => $query->where('id', $orderId))->sole();

    return [
        'policy' => $row->terms_policy_id, 'version' => $row->terms_version === null ? null : (int) $row->terms_version,
        'at' => $row->terms_accepted_at === null ? null : CarbonImmutable::parse((string) $row->terms_accepted_at)->toIso8601String(),
        'returns' => $row->returns_policy_id, 'returnsVersion' => $row->returns_version === null ? null : (int) $row->returns_version,
    ];
}

function checkoutTermsGateway(): FakeMyFatoorah
{
    $fake = FakeMyFatoorah::install();
    Cx::asSuperAdmin(function (): void {
        app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'LIVE', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
    });

    return $fake;
}

function checkoutTermsPay(string $quoteId, bool $agreed, ?ShownTerms $shown = null): string
{
    $session = app(OpenCheckoutPaymentHandler::class)->handle(new OpenCheckoutPayment(Fx::storeId('sa'), $quoteId, 'en'))->sessionId;

    return app(PayOnlineHandler::class)->handle(new PayOnline(Fx::storeId('sa'), $quoteId, $session, 'en', 'https://shop.example.test/return', $agreed, $shown ?? Cx::shown()))->tryId;
}

describe('placing (S3.3)', function () {
    it('refuses an order without the tick, whatever else is right, and places nothing', function () {
        ['address' => $address] = checkoutTermsReady();
        $quote = Cx::start($address);

        expect(fn () => checkoutTermsPlace($quote->id, false))->toThrow(TermsNotAccepted::class)
            ->and(DB::table('sales.orders')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->count())->toBe(0);
    });

    it('refuses an order in a store without published terms, ticked or not ticked (TermsMissing)', function () {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        $quote = Cx::start($address);
        checkoutTermsUnpublish($terms);

        expect(fn () => checkoutTermsPlace($quote->id, true))->toThrow(TermsMissing::class)
            ->and(fn () => checkoutTermsPlace($quote->id, false))->toThrow(TermsNotAccepted::class)
            ->and(DB::table('sales.orders')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->count())->toBe(0);
    });

    it('keeps the terms ticked on the order: the policy, its version now and when (F16), no Returns while it has none', function () {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        checkoutTermsReword($terms);
        CarbonImmutable::setTestNow($at = Cx::clockAhead());
        Carbon::setTestNow($at);

        Cx::place(Cx::start($address)->id);

        expect(checkoutTermsOf())->toBe(['policy' => $terms, 'version' => 2, 'at' => $at->toIso8601String(), 'returns' => null, 'returnsVersion' => null]);
    });

    it('keeps the Returns and Refunds policy and its version with the terms when the store has one', function () {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        $returns = Cx::asSuperAdmin(fn (): string => ContentFixtures::publishedPolicy('RETURNS'));

        Cx::place(Cx::start($address)->id);

        expect(checkoutTermsOf())->toMatchArray(['policy' => $terms, 'version' => 1, 'returns' => $returns, 'returnsVersion' => 1]);
    });

    it('refuses terms other than the ones the page showed - reworded, or Returns published since - and places nothing', function (Closure $since) {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        $quote = Cx::start($address);
        $shown = Cx::shown();
        $since($terms);

        expect(fn () => checkoutTermsPlace($quote->id, true, $shown))->toThrow(TermsChanged::class)
            ->and(DB::table('sales.orders')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->count())->toBe(0);
    })->with([
        'the terms reworded: version 2' => [fn (string $terms) => checkoutTermsReword($terms)],
        'a Returns policy published' => [fn () => Cx::asSuperAdmin(fn (): string => ContentFixtures::publishedPolicy('RETURNS'))],
    ]);

    it('refuses a page that sent none of the terms, or another policy\'s id, or another version', function (Closure $shown) {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        $quote = Cx::start($address);

        expect(fn () => checkoutTermsPlace($quote->id, true, $shown($terms)))->toThrow(TermsChanged::class)
            ->and(DB::table('sales.orders')->count())->toBe(0);
    })->with([
        'none' => [fn (): ShownTerms => new ShownTerms],
        'another policy' => [fn (): ShownTerms => new ShownTerms('01katerms0000000000000000a', 1)],
        'another version' => [fn (string $terms): ShownTerms => new ShownTerms($terms, 2)],
        'a version as words' => [fn (string $terms): ShownTerms => ShownTerms::of($terms, 'one', null, null)],
        'a version not only digits' => [fn (string $terms): ShownTerms => ShownTerms::of($terms, '1x', null, null)],
    ]);

    it('takes the version a form sends as its digits', function () {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();

        checkoutTermsPlace(Cx::start($address)->id, true, ShownTerms::of($terms, '1', null, null));

        expect(checkoutTermsOf())->toMatchArray(['policy' => $terms, 'version' => 1]);
    });

    it('answers an order already placed as it is, whatever changed in the terms since: placing twice places once', function () {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        $quote = Cx::start($address);
        $shown = Cx::shown();
        $first = checkoutTermsPlace($quote->id, true, $shown);
        checkoutTermsReword($terms);

        expect(checkoutTermsPlace($quote->id, true, $shown)->id)->toBe($first->id)
            ->and(DB::table('sales.orders')->count())->toBe(1);
    });

    it('shows staff the terms the order was placed under', function () {
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        $placed = Cx::place(Cx::start($address)->id);
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));

        $view = app(ViewOrderHandler::class)->handle(new ViewOrder($placed->id));

        expect([$view->terms?->policyId, $view->terms?->version, $view->terms?->returnsPolicyId])->toBe([$terms, 1, null]);
    });
});

describe('paying online (S3.3)', function () {
    it('refuses Pay without the tick before any try, any hold or any count against the limit', function () {
        checkoutTermsGateway();
        ['address' => $address, 'customer' => $customer] = checkoutTermsReady();
        $quote = Cx::start($address, 'ONLINE');

        expect(fn () => checkoutTermsPay($quote->id, false))->toThrow(TermsNotAccepted::class)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->count())->toBe(0)
            ->and(RateLimiter::attempts('sales:pay:customer:'.strtolower($customer)))->toBe(0);
    });

    it('refuses Pay in a store without published terms (TermsMissing), or with other terms than shown, before any try is counted', function (bool $unpublish) {
        checkoutTermsGateway();
        ['address' => $address, 'terms' => $terms, 'customer' => $customer] = checkoutTermsReady();
        $quote = Cx::start($address, 'ONLINE');
        $shown = Cx::shown();
        $unpublish ? checkoutTermsUnpublish($terms) : checkoutTermsReword($terms);

        expect(fn () => checkoutTermsPay($quote->id, true, $shown))->toThrow($unpublish ? TermsMissing::class : TermsChanged::class)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->count())->toBe(0)
            ->and(RateLimiter::attempts('sales:pay:cart:'.StoreId::fromString(Fx::storeId('sa'))->value.':'.strtolower($customer)))->toBe(0)
            ->and(RateLimiter::attempts('sales:pay:customer:'.strtolower($customer)))->toBe(0);
    })->with(['unpublished' => [true], 'reworded' => [false]]);

    it('keeps on the try what Pay ticked, and the paid order keeps that, not the terms changed meanwhile', function () {
        $fake = checkoutTermsGateway();
        ['address' => $address, 'terms' => $terms] = checkoutTermsReady();
        $quote = Cx::start($address, 'ONLINE');
        $tryId = checkoutTermsPay($quote->id, true);
        $try = DB::table('sales.checkout_tries')->where('id', $tryId)->sole();

        // The terms change while the customer is on the bank's page.
        checkoutTermsReword($terms);
        $fake->paid($quote->totals->orderTotal->toDecimal(2));
        app(FollowCheckoutPaymentHandler::class)->handle(new FollowCheckoutPayment($tryId));

        expect([$try->terms_policy_id, (int) $try->terms_version])->toBe([$terms, 1])
            ->and(checkoutTermsOf())->toMatchArray(['policy' => $terms, 'version' => 1, 'at' => CarbonImmutable::parse((string) $try->terms_accepted_at)->toIso8601String()]);
    });
});

describe('what an order keeps (F16)', function () {
    it('refuses terms it could not show staff, before the database sees them', function (Closure $terms, string $attribute) {
        expect($terms)->toThrow(InvalidSalesAttribute::class, $attribute);
    })->with([
        'version 0' => [fn () => new AcceptedTerms('01katerms0000000000000000a', 0, CarbonImmutable::now()), 'terms_version'],
        'not a policy id' => [fn () => new AcceptedTerms('terms', 1, CarbonImmutable::now()), 'terms_policy'],
        'Returns without its version' => [fn () => new AcceptedTerms('01katerms0000000000000000a', 1, CarbonImmutable::now(), '01kareturns000000000000000'), 'returns_policy'],
        'a Returns version without its policy' => [fn () => new AcceptedTerms('01katerms0000000000000000a', 1, CarbonImmutable::now(), null, 2), 'returns_policy'],
        'Returns version 0' => [fn () => new AcceptedTerms('01katerms0000000000000000a', 1, CarbonImmutable::now(), '01kareturns000000000000000', 0), 'returns_version'],
    ]);
});
