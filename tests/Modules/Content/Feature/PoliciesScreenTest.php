<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentScreen as Screen;

use function Pest\Laravel\seed;

/*
| Content › Policies over HTTP (flags-2026-11.md S3.7; the owner's answers of 2026-11-20): the list by tab with Title ·
| Kind · Stores · Version · Changed · By and its Show More, New Policy with its stores ticked, Save Draft and Publish, a
| published policy's Save Changes splitting it for the stores ticked, the stores not the person's shown read-only,
| Unpublish, Delete only while never published, refusals under their box or at the top, the job per store, the upload,
| the menu entry and the queries. The rules are PoliciesTest's.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $stores
 */
function policiesScreenEditor(array $stores = ['sa', 'eg']): AdminBrowser
{
    return Screen::as(Fx::staffWith([ContentPermissions::POLICY_MANAGE], $stores, RoleLevel::Admin));
}

/**
 * @param  array<string, mixed>  $with
 * @param  list<string>  $codes
 * @return array<string, mixed>
 */
function policiesScreenFields(array $with = [], array $codes = ['sa', 'eg']): array
{
    return [
        'kind' => 'TERMS', 'stores' => array_map(static fn (string $code): string => Fx::storeId($code), $codes), 'version' => 0,
        'title_ar' => 'الشروط والأحكام', 'title_en' => 'Terms and Conditions', 'slug_ar' => '', 'slug_en' => '',
        'body_ar' => "# الطلبات\n\nتسري **على** كل طلب.", 'body_en' => "# Orders\n\nThey apply to **every** order.", 'intent' => 'draft', ...$with,
    ];
}

function policiesScreenLatest(): string
{
    return (string) DB::table('content.policies')->orderByDesc('updated_at')->orderByDesc('id')->value('id');
}

function policiesScreenVersion(string $id): int
{
    return (int) DB::table('content.policies')->where('id', $id)->value('version');
}

it('saves a draft with its stores ticked, lists it by tab, then publishes it', function () {
    $browser = policiesScreenEditor();

    $saved = $browser->post('/admin/content/policies', policiesScreenFields(), Screen::fromPage('/admin/content/policies/new'));
    $saved->assertRedirect('/admin/content/policies?tab=draft');
    $policy = policiesScreenLatest();
    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_policies.saved'))
        ->and(DB::table('content.policy_stores')->where('policy_id', $policy)->count())->toBe(2);

    $browser->get('/admin/content/policies?tab=draft')->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->component('Content/Admin/Policies/Index')
        ->where('tab', 'draft')
        ->where('counts.DRAFT', 1)
        ->where('counts.PUBLISHED', 0)
        ->has('rows', 1)
        ->where('rows.0.kind', 'TERMS')
        ->where('rows.0.stores', ['Saudi Arabia', 'Egypt'])
        ->where('rows.0.textVersion', 0)
        // "By" is the staff member's name, never their id.
        ->where('rows.0.changedBy', fn (?string $name): bool => is_string($name) && str_contains($name, (string) DB::table('access.staff_users')->where('id', DB::table('content.policies')->value('updated_by'))->value('first_name')))
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_policies.columns.kind') && $words->has('content::policies.kinds.terms')));
    expect(array_keys($browser->get('/admin/content/policies?tab=draft', Screen::JSON)->json()))->toBe(['rows', 'next']);

    $browser->get('/admin/content/policies/new')->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->component('Content/Admin/Policies/Form')
        ->where('policy', null)
        ->where('kinds', ['TERMS', 'PRIVACY', 'RETURNS', 'SHIPPING', 'WARRANTY', 'PAYMENT_METHODS', 'COMPLAINTS', 'COMPANY_TERMS', 'OTHER'])
        ->where('stores', fn (Collection $stores): bool => $stores->pluck('code')->all() === ['sa', 'eg'])
        ->where('otherStores', []));
    $browser->get("/admin/content/policies/{$policy}")->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->where('policy.kind', 'TERMS')
        ->where('policy.ar.body', "# الطلبات\n\nتسري **على** كل طلب.")
        ->where('policy.textVersion', 0)
        ->where('shopRoot', url('')));

    $published = $browser->post("/admin/content/policies/{$policy}", policiesScreenFields(['version' => 1, 'intent' => 'publish']), Screen::fromPage("/admin/content/policies/{$policy}"));
    $published->assertRedirect('/admin/content/policies');
    expect(AdminBrowser::flashed($published, 'status'))->toBe(__('content::admin_policies.published'))
        ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('PUBLISHED')
        ->and(DB::table('content.policies')->where('id', $policy)->value('text_version'))->toBe(1);
    $browser->get('/admin/content/policies')->assertInertia(fn (AssertableInertia $screen) => $screen->where('rows.0.textVersion', 1)->where('counts.PUBLISHED', 1));
});

it('splits a published policy whose words change for the stores ticked, and lands on the copy', function () {
    $browser = policiesScreenEditor();
    $browser->post('/admin/content/policies', policiesScreenFields(['intent' => 'publish']), Screen::fromPage('/admin/content/policies/new'));
    $shared = policiesScreenLatest();

    $saved = $browser->post("/admin/content/policies/{$shared}", policiesScreenFields(['version' => 2, 'body_en' => 'New terms for Saudi Arabia.'], ['sa']), Screen::fromPage("/admin/content/policies/{$shared}"));
    $copy = (string) DB::table('content.policy_stores')->where('store_id', Fx::storeId('sa'))->value('policy_id');

    $saved->assertRedirect("/admin/content/policies/{$copy}");
    expect($copy)->not->toBe($shared)
        ->and(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_policies.split_saved'))
        ->and(DB::table('content.policy_stores')->where('policy_id', $shared)->pluck('store_id')->all())->toBe([Fx::storeId('eg')])
        ->and(DB::table('content.policies')->where('id', $copy)->value('text_version'))->toBe(1);
});

it('shows the stores a policy serves that are not the person\'s read-only, and keeps them on a save', function () {
    $both = policiesScreenEditor();
    $both->post('/admin/content/policies', policiesScreenFields(), Screen::fromPage('/admin/content/policies/new'));
    $policy = policiesScreenLatest();

    $saOnly = policiesScreenEditor(['sa']);
    $saOnly->get("/admin/content/policies/{$policy}")->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->where('stores', fn (Collection $stores): bool => $stores->pluck('code')->all() === ['sa'])
        ->where('otherStores', ['Egypt'])
        ->where('policy.stores', fn (Collection $stores): bool => $stores->count() === 2));

    // Its words unchanged, its own store ticked: one policy, Egypt still served.
    $saOnly->post("/admin/content/policies/{$policy}", policiesScreenFields(['version' => 1], ['sa']), Screen::fromPage("/admin/content/policies/{$policy}"))->assertRedirect('/admin/content/policies?tab=draft');
    expect(DB::table('content.policy_stores')->where('policy_id', $policy)->count())->toBe(2)
        ->and(DB::table('content.policies')->count())->toBe(1);

    // A store not theirs ticked is refused at the top of the form; a policy serving none of theirs is not found.
    expect(Screen::errors($saOnly->post('/admin/content/policies', policiesScreenFields([], ['eg']), Screen::fromPage('/admin/content/policies/new'))))->toHaveKey('form')
        ->and(DB::table('content.policies')->count())->toBe(1);
    policiesScreenEditor(['ae'])->get("/admin/content/policies/{$policy}")->assertNotFound();
    policiesScreenEditor(['ae'])->get('/admin/content/policies?tab=draft')->assertInertia(fn (AssertableInertia $screen) => $screen->has('rows', 0)->where('counts.DRAFT', 0));
});

it('refuses under its box, or at the top of the form, and keeps a refused Publish a draft', function () {
    $browser = policiesScreenEditor();

    expect(Screen::errors($browser->post('/admin/content/policies', policiesScreenFields(['title_ar' => '']), Screen::fromPage('/admin/content/policies/new'))))->toHaveKey('title_ar')
        ->and(Screen::errors($browser->post('/admin/content/policies', policiesScreenFields([], []), Screen::fromPage('/admin/content/policies/new'))))->toHaveKey('stores')
        ->and(Screen::errors($browser->post('/admin/content/policies', policiesScreenFields(['kind' => 'COOKIES']), Screen::fromPage('/admin/content/policies/new'))))->toHaveKey('kind')
        ->and(DB::table('content.policies')->count())->toBe(0);

    $browser->post('/admin/content/policies', policiesScreenFields(['intent' => 'publish']), Screen::fromPage('/admin/content/policies/new'));
    // A second Terms and Conditions for KSA: saved as a draft, its Publish refused at the top of its own page.
    $refused = $browser->post('/admin/content/policies', policiesScreenFields(['intent' => 'publish', 'title_ar' => 'شروط أخرى', 'title_en' => 'Other terms'], ['sa']), Screen::fromPage('/admin/content/policies/new'));
    $second = policiesScreenLatest();
    $refused->assertRedirect("/admin/content/policies/{$second}");

    expect(Screen::errors($refused)['form'] ?? '')->toContain('Terms and Conditions')
        ->and(DB::table('content.policies')->where('id', $second)->value('status'))->toBe('DRAFT');
});

it('unpublishes a policy, refuses to delete one once published, and deletes a draft never published', function () {
    $browser = policiesScreenEditor();
    $browser->post('/admin/content/policies', policiesScreenFields(['intent' => 'publish']), Screen::fromPage('/admin/content/policies/new'));
    $published = policiesScreenLatest();

    $browser->post("/admin/content/policies/{$published}/unpublish", ['version' => policiesScreenVersion($published)], Screen::fromPage("/admin/content/policies/{$published}"))->assertRedirect();
    expect(DB::table('content.policies')->where('id', $published)->value('status'))->toBe('DRAFT');

    $refused = $browser->post("/admin/content/policies/{$published}/delete", ['version' => policiesScreenVersion($published)], Screen::fromPage("/admin/content/policies/{$published}"));
    expect(Screen::errors($refused))->toHaveKey('form')
        ->and(DB::table('content.policies')->where('id', $published)->exists())->toBeTrue();

    $browser->post('/admin/content/policies', policiesScreenFields(['kind' => 'PRIVACY', 'title_ar' => 'الخصوصية', 'title_en' => 'Privacy']), Screen::fromPage('/admin/content/policies/new'));
    $draft = policiesScreenLatest();
    $browser->post("/admin/content/policies/{$draft}/delete", ['version' => 1], Screen::fromPage("/admin/content/policies/{$draft}"))->assertRedirect('/admin/content/policies?tab=draft');
    expect(DB::table('content.policies')->where('id', $draft)->exists())->toBeFalse();
});

it('unpublishes in the stores ticked: one of two lands on the switched-off copy, a refused store says so on the stores (owner, 2026-11-20)', function () {
    $browser = policiesScreenEditor();
    $browser->post('/admin/content/policies', policiesScreenFields(['intent' => 'publish']), Screen::fromPage('/admin/content/policies/new'));
    $shared = policiesScreenLatest();
    // Ahmed holds KSA alone: Egypt is not his to tick.
    $ahmed = policiesScreenEditor(['sa']);

    $browser = $ahmed;
    $refused = $browser->post("/admin/content/policies/{$shared}/unpublish", ['version' => policiesScreenVersion($shared), 'stores' => [Fx::storeId('eg')]], Screen::fromPage("/admin/content/policies/{$shared}"));
    expect(Screen::errors($refused)['form'] ?? '')->toContain('isn\'t one of your jobs')
        ->and(DB::table('content.policies')->where('id', $shared)->value('status'))->toBe('PUBLISHED');

    // A store it does not serve, ticked by one who holds it: said on the stores.
    $unknown = policiesScreenEditor(['sa', 'ae'])->post("/admin/content/policies/{$shared}/unpublish", ['version' => policiesScreenVersion($shared), 'stores' => [Fx::storeId('ae')]], Screen::fromPage("/admin/content/policies/{$shared}"));
    expect(Screen::errors($unknown))->toHaveKey('stores')
        ->and(DB::table('content.policies')->count())->toBe(1);

    $off = $browser->post("/admin/content/policies/{$shared}/unpublish", ['version' => policiesScreenVersion($shared), 'stores' => [Fx::storeId('sa')]], Screen::fromPage("/admin/content/policies/{$shared}"));
    $copy = (string) DB::table('content.policies')->where('id', '<>', $shared)->value('id');

    $off->assertRedirect("/admin/content/policies/{$copy}");
    expect(AdminBrowser::flashed($off, 'status'))->toBe(__('content::admin_policies.turned_off'))
        ->and(DB::table('content.policies')->where('id', $copy)->value('status'))->toBe('DRAFT')
        ->and(DB::table('content.policies')->where('id', $shared)->value('status'))->toBe('PUBLISHED')
        ->and(DB::table('content.policy_stores')->where('policy_id', $shared)->pluck('store_id')->all())->toBe([Fx::storeId('eg')]);

    // The copy's page, his: KSA ticked, nothing else served.
    $browser->get("/admin/content/policies/{$copy}")->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->where('policy.stores', [Fx::storeId('sa')])
        ->where('policy.textVersion', 0)
        ->where('otherStores', []));
});

it('deletes a draft in the stores ticked: some keep the person on its page, all of them back to the list (owner, 2026-11-20)', function () {
    $browser = policiesScreenEditor(['sa', 'eg', 'ae']);
    $browser->post('/admin/content/policies', policiesScreenFields([], ['sa', 'eg', 'ae']), Screen::fromPage('/admin/content/policies/new'));
    $draft = policiesScreenLatest();

    $some = $browser->post("/admin/content/policies/{$draft}/delete", ['version' => 1, 'stores' => [Fx::storeId('ae')]], Screen::fromPage("/admin/content/policies/{$draft}"));
    $some->assertRedirect("/admin/content/policies/{$draft}");
    expect(AdminBrowser::flashed($some, 'status'))->toBe(__('content::admin_policies.deleted_in'))
        ->and(DB::table('content.policy_stores')->where('policy_id', $draft)->count())->toBe(2);

    $all = $browser->post("/admin/content/policies/{$draft}/delete", ['version' => 2, 'stores' => [Fx::storeId('sa'), Fx::storeId('eg')]], Screen::fromPage("/admin/content/policies/{$draft}"));
    $all->assertRedirect('/admin/content/policies?tab=draft');
    expect(DB::table('content.policies')->where('id', $draft)->exists())->toBeFalse();
});

it('uploads an image under the job only', function () {
    Storage::fake('local');
    Storage::fake('public');
    $file = fn () => UploadedFile::fake()->image('photo.jpg', 800, 600);

    policiesScreenEditor(['sa'])->post('/admin/content/uploads/policy', ['store' => Fx::storeId('sa'), 'file' => $file()], Screen::JSON)->assertCreated();
    policiesScreenEditor(['sa'])->post('/admin/content/uploads/policy', ['store' => Fx::storeId('eg'), 'file' => $file()], Screen::JSON)->assertForbidden();
    Screen::as(Fx::staffWith([ContentPermissions::PAGE_MANAGE], ['sa'], RoleLevel::Admin))->post('/admin/content/uploads/policy', ['store' => Fx::storeId('sa'), 'file' => $file()], Screen::JSON)->assertForbidden();
});

it('refuses the screens without the job, and offers the menu entry only to whoever holds it', function () {
    $pages = Screen::as(Fx::staffWith([ContentPermissions::PAGE_MANAGE], ['sa'], RoleLevel::Admin));

    $pages->get('/admin/content/policies')->assertForbidden();
    $pages->get('/admin/content/policies/new')->assertForbidden();
    expect(Screen::menu($pages))->toBe(['pages'])
        ->and(Screen::menu(policiesScreenEditor(['sa'])))->toBe(['policies']);
});

it('costs few queries of its own', function () {
    $browser = policiesScreenEditor();
    $browser->post('/admin/content/policies', policiesScreenFields(), Screen::fromPage('/admin/content/policies/new'));
    $policy = policiesScreenLatest();

    // Measured 2026-11-20: the panel's frame's 10 (AdminFrameQueryBudgetTest) and the page's own - the list 4 (its rows,
    // their stores, the tabs' counts, the names of who changed them), New Policy 0 (the stores offered and their names
    // come from the frame's reads), a policy's form 5 (the policy's row, translations, images and stores, and its
    // Translate mark; its files' two when it has any). Content's target is 15 of its own (content.md §4.2).
    expect([
        Screen::queries($browser, '/admin/content/policies?tab=draft'),
        Screen::queries($browser, '/admin/content/policies/new'),
        Screen::queries($browser, "/admin/content/policies/{$policy}"),
    ])->toBe([14, 10, 15]);
});
