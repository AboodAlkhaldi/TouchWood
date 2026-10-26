<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Feedback's shop screens over HTTP (feedback.md §4.1 F5, F6, F8): the product page carrying the words its
| sections draw in, a review written and a question asked from it, the Save heart - a guest sent to sign
| in and brought back with it saved - and the account's My Reviews, My Questions and My Wishlist, with the
| queries each reads.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** @return non-empty-string */
function shopScreensUrl(string $productId, string $locale = 'en'): string
{
    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $productId)->where('locale', $locale)->where('is_current', true)->value('slug');

    return "/sa/{$locale}/products/{$slug}";
}

/**
 * A customer, and a browser signed in as them.
 *
 * @return array{0: string, 1: AdminBrowser}
 */
function shopScreensCustomer(string $email = 'sara@example.test'): array
{
    $customerId = Fx::customer($email);
    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return [$customerId, $browser];
}

/**
 * @return array<string, mixed>
 */
function shopScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

function shopScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Feedback/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

describe('F5, F6 · The product page', function () {
    it('carries the words Feedback\'s sections draw in, in the page\'s language', function () {
        ['product' => $product] = Fb::sold();
        $words = static fn (string $locale): mixed => shopScreensProps(new AdminBrowser, shopScreensUrl($product, $locale), 'Catalog/Storefront/Product')['translations']['feedback::shop.reviews.title'] ?? null;

        expect([$words('en'), $words('ar')])->toBe(['Reviews', 'التقييمات']);
    });

    it('takes a review from a customer who received the product, and refuses a second', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        [$sara, $browser] = shopScreensCustomer();
        Fb::delivered($sara, $product, $sixty);
        $page = shopScreensUrl($product);
        $browser->get($page)->assertOk();

        $written = $browser->post("/sa/en/products/{$product}/reviews", ['rating' => 4, 'body' => 'Solid and quiet.']);
        $again = $browser->post("/sa/en/products/{$product}/reviews", ['rating' => 5, 'body' => 'Twice.']);

        expect(AdminBrowser::flashed($written, 'status'))->toBe('Thanks — your review shows once the store approves it.')
            ->and($written->headers->get('Location'))->toEndWith($page)
            ->and(DB::table('feedback.reviews')->where('customer_id', $sara)->get(['rating', 'status'])->map(fn ($row) => (array) $row)->all())->toBe([['rating' => 4, 'status' => 'PENDING']])
            ->and(AdminBrowser::formError($again))->toContain('already reviewed');
    });

    it('takes a question, and sends a guest who tries to write to the store\'s home', function () {
        ['product' => $product] = Fb::sold();
        [$sara, $browser] = shopScreensCustomer();

        $asked = $browser->post("/sa/en/products/{$product}/questions", ['body' => 'Does it fit a 60 cm cabinet?']);
        $guest = (new AdminBrowser)->post("/sa/en/products/{$product}/questions", ['body' => 'Me too?']);

        expect(AdminBrowser::flashed($asked, 'status'))->toBe('Thanks — the answer shows here and in your account.')
            ->and(DB::table('feedback.questions')->where('customer_id', $sara)->value('locale'))->toBe('en')
            ->and($guest->headers->get('Location'))->toEndWith('/sa/en')
            ->and(DB::table('feedback.questions')->count())->toBe(1);
    });

    it('saves and removes with the heart for a signed-in customer', function () {
        ['product' => $product] = Fb::sold();
        [$sara, $browser] = shopScreensCustomer();
        $saved = static fn (): bool => DB::table('feedback.wishlist_items')->where('customer_id', $sara)->where('product_id', $product)->exists();

        $browser->post("/sa/en/products/{$product}/save");
        $after = $saved();
        $browser->post("/sa/en/products/{$product}/remove");

        expect([$after, $saved()])->toBe([true, false]);
    });

    it('sends a guest who presses the heart to sign in, and brings them back with it saved', function () {
        ['product' => $product] = Fb::sold();
        $sara = Fx::customer('sara@example.test');
        $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
        $page = shopScreensUrl($product);

        $pressed = $browser->post("/sa/en/products/{$product}/save", ['back' => $page]);
        $signedIn = $browser->post('/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD]);
        $back = $browser->get((string) $signedIn->headers->get('Location'));

        expect($pressed->headers->get('Location'))->toEndWith('/sa/en/sign-in')
            ->and((string) $signedIn->headers->get('Location'))->toEndWith('/sa/en/wishlist/after-sign-in')
            ->and($back->headers->get('Location'))->toEndWith($page)
            ->and(DB::table('feedback.wishlist_items')->where('customer_id', $sara)->where('product_id', $product)->exists())->toBeTrue();
    });

    it('never goes back anywhere but this site, and saves nothing for an address it did not sign', function () {
        ['product' => $product] = Fb::sold();
        [$sara, $browser] = shopScreensCustomer();

        // Made before the requests: made between the guest's two, this test's sign-in went home (the
        // fixtures act as the system in this process; in a real request each starts fresh).
        Fx::customer('layla@example.test');

        // No heart pressed in this session: the address alone saves nothing, whoever sent the link.
        $forged = $browser->get('/sa/en/wishlist/after-sign-in');
        $guest = (new AdminBrowser('10.9.8.'.random_int(20, 250)));
        $guest->post("/sa/en/products/{$product}/save", ['back' => '//evil.example/x']);
        $intended = (string) $guest->post('/sa/en/account/sign-in', ['email' => 'layla@example.test', 'password' => Fx::CUSTOMER_PASSWORD])->headers->get('Location');

        expect($forged->headers->get('Location'))->toEndWith('/sa/en')
            ->and(DB::table('feedback.wishlist_items')->where('customer_id', $sara)->exists())->toBeFalse()
            ->and($intended)->toEndWith('/sa/en/wishlist/after-sign-in')
            // The heart pressed with an address of another host: saved, and back to this store's home.
            ->and((string) $guest->get($intended)->headers->get('Location'))->toEndWith('/sa/en');
    });
});

describe('F8 · The account\'s pages', function () {
    it('lists the customer\'s reviews in every store, with their state and the store\'s reason', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'ae']);
        ['product' => $other, 'variants' => [$eighty]] = Fb::sold(['sa', 'ae']);
        [$sara, $browser] = shopScreensCustomer();
        Fb::reviewRow($product, $sixty, $sara, ['status' => 'REJECTED', 'reason' => 'Off topic', 'moderated_by' => Fx::staff(), 'moderated_at' => CarbonImmutable::now()]);
        Fb::reviewRow($other, $eighty, $sara, [], 'ae');

        $props = shopScreensProps($browser, '/sa/en/account/reviews', 'Feedback/Storefront/MyReviews');

        /** @var list<array{status: string, reason: ?string, product: array{href: ?string}}> $reviews */
        $reviews = $props['reviews'];
        $byStatus = array_column($reviews, null, 'status');
        $stores = array_map(static fn (array $review): string => substr((string) $review['product']['href'], 0, 4), $reviews);
        sort($stores);

        expect(array_keys($byStatus))->toEqualCanonicalizing(['REJECTED', 'PENDING'])
            ->and($byStatus['REJECTED']['reason'])->toBe('Off topic')
            ->and($stores)->toBe(['/ae/', '/sa/']);
    });

    it('lists the customer\'s questions with the answer once given', function () {
        ['product' => $product] = Fb::sold();
        [$sara, $browser] = shopScreensCustomer();
        DB::table('feedback.questions')->insert([
            'id' => strtolower((string) Str::ulid()), 'product_id' => $product, 'store_id' => Fx::storeId('sa'), 'customer_id' => $sara,
            'body' => 'Does it fit?', 'locale' => 'en', 'status' => 'ANSWERED', 'answer' => 'Yes.', 'answered_by' => Fx::staff(),
            'answered_at' => CarbonImmutable::now(), 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);

        $props = shopScreensProps($browser, '/sa/en/account/questions', 'Feedback/Storefront/MyQuestions');

        expect([$props['questions'][0]['status'], $props['questions'][0]['answer'], $props['next']])->toBe(['ANSWERED', 'Yes.', null]);
    });

    it('groups the wishlist by store, and offers the three pages in the account menu', function () {
        ['product' => $product] = Fb::sold(['sa', 'ae']);
        [$sara, $browser] = shopScreensCustomer();
        foreach (['sa', 'ae'] as $code) {
            DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId($code), 'saved_at' => CarbonImmutable::now()]);
        }

        $props = shopScreensProps($browser, '/sa/en/account/wishlist', 'Feedback/Storefront/MyWishlist');

        expect(array_column($props['stores'], 'storeCode'))->toEqualCanonicalizing(['sa', 'ae'])
            ->and($props['stores'][0]['items'][0]['product']['id'])->toBe($product)
            ->and(array_column($props['accountMenu']['pages'], 'key'))->toContain('feedback.reviews', 'feedback.questions', 'feedback.wishlist');
    });

    it('reads each account page in a fixed few queries of its own, the same for one row as for six', function () {
        [$sara, $browser] = shopScreensCustomer();
        $add = static function (int $n) use ($sara): void {
            ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
            Fb::reviewRow($product, $sixty, $sara);
            DB::table('feedback.questions')->insert([
                'id' => strtolower((string) Str::ulid()), 'product_id' => $product, 'store_id' => Fx::storeId('sa'), 'customer_id' => $sara,
                'body' => "Question {$n}", 'locale' => 'en', 'status' => 'PENDING', 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
            ]);
            DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $product, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);
        };
        $pages = ['/sa/en/account/reviews', '/sa/en/account/questions', '/sa/en/account/wishlist'];
        $counts = static fn (): array => array_map(static fn (string $uri): int => shopScreensOwnQueries($browser, $uri), $pages);
        $add(0);
        $one = $counts();

        foreach (range(1, 5) as $n) {
            $add($n);
        }

        expect($counts())->toBe($one)
            ->and($one)->toBe([6, 4, 4]);
    });
});
