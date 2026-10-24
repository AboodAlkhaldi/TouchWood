<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Feedback\Application\FeedbackPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Feedback's Support screens over HTTP (feedback.md §4.1 F1–F4): the review and question queues and
| their decisions, Favourited Products, and a customer's wishlist on the staff customer page - what each
| page is given, who is refused, and the queries each reads.
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
});

/**
 * @param  list<string>  $permissions
 */
function supportScreens(array $permissions, array $stores = ['sa']): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores);
    $browser = new AdminBrowser('10.9.5.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @return array<string, mixed>
 */
function supportScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

function supportScreensCustomer(string $email, string $first, string $last = 'Screen'): string
{
    $id = Fx::customer($email);
    DB::table('access.customers')->where('id', $id)->update(['first_name' => $first, 'last_name' => $last]);

    return $id;
}

/** A question written straight into its table, waiting unless told otherwise. */
function supportScreensQuestion(string $productId, string $customerId, array $with = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('feedback.questions')->insert([
        'id' => $id, 'product_id' => $productId, 'store_id' => Fx::storeId('sa'), 'customer_id' => $customerId,
        'body' => 'Does it fit a 60 cm cabinet?', 'locale' => 'en', 'status' => 'PENDING',
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(), ...$with,
    ]);

    return $id;
}

function supportScreensSaved(string $customerId, string $productId): void
{
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $customerId, 'product_id' => $productId, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);
}

function supportScreensOwnQueries(AdminBrowser $browser, string $uri): int
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

describe('F1 · Reviews', function () {
    it('lists the waiting reviews oldest first, each author by their public name only', function () {
        $sold = Fb::sold();
        $older = Fb::reviewRow($sold['product'], $sold['variants'][0], supportScreensCustomer('a@example.test', 'Amal', 'Saleh'), ['created_at' => CarbonImmutable::now()->subDay()]);
        $newer = Fb::reviewRow($sold['product'], $sold['variants'][1], supportScreensCustomer('b@example.test', 'Badr'));

        $props = supportScreensProps(supportScreens([FeedbackPermissions::REVIEW_MODERATE]), '/admin/reviews', 'Feedback/Admin/Reviews');

        expect(array_column($props['reviews'], 'id'))->toBe([$older, $newer])
            ->and($props['reviews'][0]['author'])->toBe(['firstName' => 'Amal', 'lastInitial' => 'S', 'deleted' => false])
            ->and([$props['status'], $props['mayAct'], $props['storeCode']])->toBe(['PENDING', true, 'sa']);
    });

    it('approves at once, and rejects or unpublishes with an optional reason, saying each', function () {
        $sold = Fb::sold();
        $first = Fb::reviewRow($sold['product'], $sold['variants'][0], supportScreensCustomer('a@example.test', 'Amal'));
        $second = Fb::reviewRow($sold['product'], $sold['variants'][1], supportScreensCustomer('b@example.test', 'Badr'));
        $browser = supportScreens([FeedbackPermissions::REVIEW_MODERATE]);

        $approved = $browser->post("/admin/reviews/{$first}/approve");
        $rejected = $browser->post("/admin/reviews/{$second}/reject", ['reason' => 'Not about the product']);
        $unpublished = $browser->post("/admin/reviews/{$first}/unpublish", ['reason' => '']);
        $status = static fn (string $id): array => (array) DB::table('feedback.reviews')->where('id', $id)->first(['status', 'reason']);

        expect([AdminBrowser::flashed($approved, 'status'), AdminBrowser::flashed($rejected, 'status'), AdminBrowser::flashed($unpublished, 'status')])
            ->toBe(['Review approved.', 'Review rejected.', 'Review unpublished.'])
            ->and($status($second))->toBe(['status' => 'REJECTED', 'reason' => 'Not about the product'])
            ->and($status($first))->toBe(['status' => 'UNPUBLISHED', 'reason' => null]);
    });

    it('refuses a reader without the job the page, and their decisions', function () {
        $sold = Fb::sold();
        $review = Fb::reviewRow($sold['product'], $sold['variants'][0], supportScreensCustomer('a@example.test', 'Amal'));
        $other = supportScreens([FeedbackPermissions::QUESTION_ANSWER]);

        $other->get('/admin/reviews')->assertForbidden();
        $refused = $other->post("/admin/reviews/{$review}/approve");

        expect([$refused->getStatusCode(), DB::table('feedback.reviews')->where('id', $review)->value('status')])->toBe([302, 'PENDING']);
    });
});

describe('F2 · Product Questions', function () {
    it('answers a waiting question, corrects the answer, and rejects another with a reason', function () {
        $sold = Fb::sold();
        $asked = supportScreensQuestion($sold['product'], supportScreensCustomer('a@example.test', 'Amal'));
        $other = supportScreensQuestion($sold['product'], supportScreensCustomer('b@example.test', 'Badr'));
        $browser = supportScreens([FeedbackPermissions::QUESTION_ANSWER]);

        $props = supportScreensProps($browser, '/admin/questions', 'Feedback/Admin/Questions');
        $answered = $browser->post("/admin/questions/{$asked}/answer", ['answer' => 'Yes, it fits.']);
        $corrected = $browser->post("/admin/questions/{$asked}/correct", ['answer' => 'Yes, a 60 cm cabinet.']);
        $rejected = $browser->post("/admin/questions/{$other}/reject", ['reason' => 'Asked twice']);
        $empty = $browser->post("/admin/questions/{$other}/answer", ['answer' => '']);

        expect(array_column($props['questions'], 'id'))->toEqualCanonicalizing([$asked, $other])
            ->and([AdminBrowser::flashed($answered, 'status'), AdminBrowser::flashed($corrected, 'status'), AdminBrowser::flashed($rejected, 'status')])
            ->toBe(['Answer published.', 'Answer corrected.', 'Question rejected.'])
            ->and(DB::table('feedback.questions')->where('id', $asked)->value('answer'))->toBe('Yes, a 60 cm cabinet.')
            ->and($empty->getStatusCode())->toBe(302);
    });
});

describe('F3 · Favourited Products', function () {
    it('counts how many customers saved each product here, most saved first', function () {
        $one = Fb::sold()['product'];
        $two = Fb::sold()['product'];
        supportScreensSaved(supportScreensCustomer('a@example.test', 'Amal'), $two);
        supportScreensSaved(supportScreensCustomer('b@example.test', 'Badr'), $two);
        supportScreensSaved(supportScreensCustomer('c@example.test', 'Carim'), $one);

        $props = supportScreensProps(supportScreens([FeedbackPermissions::WISHLIST_VIEW]), '/admin/favourites', 'Feedback/Admin/Favourites');

        expect(array_map(static fn (array $row): array => [$row['product']['id'], $row['savedBy']], $props['favourites']))->toBe([[$two, 2], [$one, 1]]);
    });
});

describe('F4 · A customer\'s wishlist on the staff customer page', function () {
    it('shows the reader\'s stores\' saved products, and no section to a reader without the job', function () {
        $product = Fb::sold()['product'];
        $customer = supportScreensCustomer('a@example.test', 'Amal');
        supportScreensSaved($customer, $product);

        $reader = supportScreensProps(supportScreens([AccessPermissions::CUSTOMER_VIEW, FeedbackPermissions::WISHLIST_VIEW]), "/admin/customers/{$customer}", 'Access/Admin/Customers/Show');
        $other = supportScreensProps(supportScreens([AccessPermissions::CUSTOMER_VIEW]), "/admin/customers/{$customer}", 'Access/Admin/Customers/Show');
        $section = collect($reader['sections'])->firstWhere('key', 'wishlist');

        expect($section['data']['stores'][0]['items'][0]['product']['id'] ?? null)->toBe($product)
            ->and(collect($other['sections'])->firstWhere('key', 'wishlist'))->toBeNull();
    });
});

it('reads each Support page in a fixed few queries of its own, the same for one row as for six', function () {
    $sold = Fb::sold();
    Fb::reviewRow($sold['product'], $sold['variants'][0], supportScreensCustomer('q0@example.test', 'Q0'));
    supportScreensQuestion($sold['product'], supportScreensCustomer('p0@example.test', 'P0'));
    supportScreensSaved(supportScreensCustomer('s0@example.test', 'S0'), $sold['product']);
    $pages = ['/admin/reviews', '/admin/questions', '/admin/favourites'];
    $counts = static fn (): array => array_map(static fn (string $uri): int => supportScreensOwnQueries(supportScreens([FeedbackPermissions::REVIEW_MODERATE, FeedbackPermissions::QUESTION_ANSWER, FeedbackPermissions::WISHLIST_VIEW]), $uri), $pages);
    $one = $counts();

    foreach (range(1, 5) as $n) {
        $more = Fb::sold();
        Fb::reviewRow($more['product'], $more['variants'][0], supportScreensCustomer("q{$n}@example.test", "Q{$n}"));
        supportScreensQuestion($more['product'], supportScreensCustomer("p{$n}@example.test", "P{$n}"));
        supportScreensSaved(supportScreensCustomer("s{$n}@example.test", "S{$n}"), $more['product']);
    }

    expect($counts())->toBe($one)
        ->and($one)->toBe([9, 7, 6]);
});
