<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Who sees a category, on the categories screen (catalog.md §4.4 S2, amendment 14): for the store
| chosen in the page's own filter — the stores where the reader orders the menu or chooses who sees
| the categories — each category's Individuals and Companies switches, each saved the moment it flips
| (P9), for an admin role holding `catalog.category.audience` in that store; everyone else sees them,
| out of reach. The page's own queries recorded under the admin budget of 15 (frontend.md §5).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
});

/**
 * Signed in through the panel's door with a role of this level ('*' for All stores).
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function catalogAudienceScreen(array $permissions, array $stores = ['*'], RoleLevel $level = RoleLevel::Admin): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores, $level);
    $browser = new AdminBrowser('10.9.5.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @param  TestResponse<Response>  $response
 */
function catalogAudienceScreenToast(TestResponse $response): mixed
{
    return AdminBrowser::flashed($response, 'status');
}

/**
 * @return array{bool, bool}|null
 */
function catalogAudienceScreenRow(string $store, string $categoryId): ?array
{
    $row = DB::table('catalog.store_category_audiences')->where('store_id', Fx::storeId($store))->where('category_id', $categoryId)->first();

    return $row instanceof stdClass ? [(bool) $row->for_individuals, (bool) $row->for_companies] : null;
}

/**
 * The page's own queries, opened a second time (warm), the panel's frame not counted.
 */
function catalogAudienceScreenOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Catalog/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

describe('the page', function () {
    it('shows, for the store chosen, who sees each category — a category nobody switched seen by both', function () {
        $top = Px::category('Kitchens');
        $below = Px::category('Drawers', $top);
        $other = Px::category('Wardrobes');
        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('eg'), $top, false)));
        $row = static fn (Collection $categories, string $id): array => (array) $categories->first(fn (array $category): bool => $category['id'] === $id);

        catalogAudienceScreen([P::CATEGORY_AUDIENCE], ['eg'])->get('/admin/categories?store=eg')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/Admin/Categories/Index')
                ->where('storeCode', 'eg')
                ->where('mayChooseAudience', true)
                ->where('mayRank', false)
                ->where('mayManage', false)
                ->where('stores', [['id' => Fx::storeId('eg'), 'code' => 'eg', 'name' => 'Egypt', 'isActive' => true]])
                ->where('categories', fn (Collection $categories): bool => $row($categories, $top)['forIndividuals'] === false
                    && $row($categories, $top)['forCompanies'] === true
                    && $row($categories, $below)['forIndividuals'] === false
                    && $row($categories, $other)['forIndividuals'] === true
                    && $row($categories, $other)['forCompanies'] === true)
            );
    });

    it('offers the stores where the reader orders the menu or chooses who sees the categories, and says which they may do there', function () {
        Px::category('Kitchens');
        $browser = catalogAudienceScreen([P::CATEGORY_RANK, P::CATEGORY_AUDIENCE], ['sa', 'eg']);

        $browser->get('/admin/categories?store=sa')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mayRank', true)->where('mayChooseAudience', true));

        // Someone who only orders the menu: the switches shown, out of reach.
        catalogAudienceScreen([P::CATEGORY_RANK], ['sa'])->get('/admin/categories?store=sa')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mayRank', true)->where('mayChooseAudience', false));

        // Someone who only chooses who sees: offered that store, not another.
        $chooser = catalogAudienceScreen([P::CATEGORY_AUDIENCE], ['eg']);
        $chooser->get('/admin/categories')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', 'eg'));
        $chooser->get('/admin/categories?store=sa')->assertForbidden();
    });

    it('is offered in the menu to whoever chooses who sees the categories in some store', function () {
        $response = catalogAudienceScreen([P::CATEGORY_AUDIENCE], ['eg'])->get('/admin');
        $offered = [];

        $response->assertInertia(function (AssertableInertia $page) use (&$offered): void {
            foreach ($page->toArray()['props']['menu'] as $group) {
                foreach ($group['entries'] as $entry) {
                    $offered[] = $group['key'].'/'.$entry['key'];
                }
            }
        });

        expect($offered)->toContain('catalog/categories');
    });

    it('reads the page in its own recorded number of queries, warm, however many categories and switches', function () {
        $browser = catalogAudienceScreen([P::CATEGORY_AUDIENCE, P::CATEGORY_RANK, P::CATEGORY_MANAGE]);
        $top = Px::category('Kitchens');
        $own = catalogAudienceScreenOwnQueries($browser, '/admin/categories?store=sa');

        foreach (range(1, 5) as $n) {
            Px::category("Branch {$n}", $top);
        }

        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $top, false, false)));

        expect($own)->toBe(3)
            ->and(catalogAudienceScreenOwnQueries($browser, '/admin/categories?store=sa'))->toBe($own);
    });
});

describe('a switch', function () {
    it('saves the moment it flips, for the store shown, reaching everything under it', function () {
        $top = Px::category('Kitchens');
        $below = Px::category('Drawers', $top);
        $browser = catalogAudienceScreen([P::CATEGORY_AUDIENCE], ['sa']);

        expect(catalogAudienceScreenToast($browser->post("/admin/categories/{$top}/audience", ['store' => 'sa', 'individuals' => '0'])))->toBe('Who sees it saved')
            ->and(catalogAudienceScreenRow('sa', $top))->toBe([false, true])
            ->and(catalogAudienceScreenRow('sa', $below))->toBe([false, true])
            ->and(catalogAudienceScreenToast($browser->post("/admin/categories/{$below}/audience", ['store' => 'sa', 'companies' => 'false'])))->toBe('Who sees it saved')
            ->and(catalogAudienceScreenRow('sa', $below))->toBe([false, false])
            ->and(catalogAudienceScreenRow('sa', $top))->toBe([false, true])
            ->and(catalogAudienceScreenToast($browser->post("/admin/categories/{$top}/audience", ['store' => 'sa', 'individuals' => '1'])))->toBe('Who sees it saved')
            ->and(catalogAudienceScreenRow('sa', $below))->toBe([true, false]);
    });

    it('is refused, nothing written, without the job in the store named, with no store, and with no switch or one that is not on or off', function () {
        $category = Px::category('Kitchens');
        $browser = catalogAudienceScreen([P::CATEGORY_AUDIENCE], ['sa']);
        $notYours = trans('errors.unauthorized.detail', [], 'en');

        expect(AdminBrowser::formError($browser->post("/admin/categories/{$category}/audience", ['store' => 'eg', 'individuals' => '0'])))->toBe($notYours)
            ->and(AdminBrowser::formError($browser->post("/admin/categories/{$category}/audience", ['individuals' => '0'])))->toBe($notYours)
            ->and(AdminBrowser::formError($browser->post("/admin/categories/{$category}/audience", ['store' => 'sa'])))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post("/admin/categories/{$category}/audience", ['store' => 'sa', 'individuals' => 'maybe'])))->not->toBeNull()
            ->and(DB::table('catalog.store_category_audiences')->count())->toBe(0);

        // A role that orders the menu, all stores, is not one that chooses who sees.
        $ranker = catalogAudienceScreen([P::CATEGORY_RANK, P::CATEGORY_MANAGE]);

        expect(AdminBrowser::formError($ranker->post("/admin/categories/{$category}/audience", ['store' => 'sa', 'individuals' => '0'])))->toBe($notYours)
            ->and(DB::table('catalog.store_category_audiences')->count())->toBe(0);
    });
});
