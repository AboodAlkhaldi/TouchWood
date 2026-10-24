<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Feedback in the shop, in a real browser (feedback.md §4.1 F5, F6, F8). The feature tests beside these
| prove what the pages are handed and what the writes do; these prove a shopper can use them: the
| reviews narrowed to one star from its bar, a review written and a question asked from their dialogs,
| the Save heart, and the product page and the account's pages at a phone's width with nothing
| scrolling sideways.
|
| Deliberately no RefreshDatabase, as the other browser tests: every row here is new, from the clock.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CatalogProducts::numberFrom((int) substr((string) hrtime(true), -8));
});

function feedbackShopSlug(string $productId): string
{
    return (string) DB::table('catalog.product_slugs')->where('product_id', $productId)->where('locale', 'en')->where('is_current', true)->value('slug');
}

/** A customer, signed in to the shop in a real browser. */
function feedbackShopCustomer(string $customerId): mixed
{
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    $page = visit('/sa/en/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');

    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue();

    return $page;
}

function feedbackShopWide(mixed $page): string
{
    return (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');
}

it('narrows the reviews to one star from its bar, and sends a guest who presses Save to sign in', function () {
    ['product' => $product, 'variants' => [$sixty, $eighty]] = Fb::sold();
    $approved = ['status' => 'APPROVED', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()];
    Fb::reviewRow($product, $sixty, Fx::customer(strtolower((string) Str::ulid()).'@example.test'), [...$approved, 'rating' => 5, 'body' => 'Five stars, quiet.']);
    Fb::reviewRow($product, $eighty, Fx::customer(strtolower((string) Str::ulid()).'@example.test'), [...$approved, 'rating' => 2, 'body' => 'Two stars, loud.']);

    $page = visit('/sa/en/products/'.feedbackShopSlug($product))
        ->assertSee('Reviews')
        ->assertSee('Five stars, quiet.')
        ->assertSee('Two stars, loud.')
        ->click('[data-test="star-2"]');

    expect(browserUntil($page, "location.search.includes('filter')"))->toBeTrue();
    $page->assertSee('Two stars, loud.')->assertDontSee('Five stars, quiet.');

    $page->click('[data-test="save-heart"]');
    expect(browserUntil($page, "location.pathname === '/sa/en/sign-in'"))->toBeTrue();
});

it('writes a review, asks a question and saves the product, then shows it all in the account, at a phone\'s width', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $customer = Fx::customer(strtolower((string) Str::ulid()).'@example.test');
    Fb::delivered($customer, $product, $sixty);
    $page = feedbackShopCustomer($customer);
    $page->resize(375, 812);

    $page->navigate('/sa/en/products/'.feedbackShopSlug($product), BROWSER_PAGE_LOAD)
        ->click('[data-test="write-review"]')
        ->click('[data-test="rate-4"]')
        ->type('#review-body', 'Solid and quiet.')
        ->click('[data-test="submit-review"]');
    expect(browserUntil($page, "document.body.innerText.includes('You reviewed this product.')"))->toBeTrue();

    $page->click('[data-test="ask-question"]')
        ->type('#question-body', 'Does it fit a 60 cm cabinet?')
        ->click('[data-test="submit-question"]');
    expect(browserUntil($page, "document.body.innerText.includes('the answer shows here')"))->toBeTrue();

    $page->click('[data-test="save-heart"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"save-heart\"]')?.getAttribute('aria-pressed') === 'true'"))->toBeTrue()
        ->and(feedbackShopWide($page))->toBe('', 'the product page scrolls sideways');

    foreach (['/sa/en/account/reviews' => 'Solid and quiet.', '/sa/en/account/questions' => 'Does it fit a 60 cm cabinet?', '/sa/en/account/wishlist' => 'Remove'] as $uri => $text) {
        $page->navigate($uri, BROWSER_PAGE_LOAD)->assertSee($text);

        expect(feedbackShopWide($page))->toBe('', "{$uri} scrolls sideways");
    }
});
