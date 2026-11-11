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
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentScreen as Screen;
use Tests\Modules\Pricing\Support\PricingFixtures as Pf;

use function Pest\Laravel\seed;

/*
| W7 · Pages, W8 · Blog, W9 · Projects over HTTP (content.md §4.4, §1.11-§1.13): the lists by tab and their Show More,
| an item's own page, Save Draft and Publish, Translate's unread mark asked for again in a later visit (P14), a
| published item's Save Changes, Unpublish and Delete with where links name it, the blog's categories, a post's and a
| project's own fields, the product search, the uploads under each kind's permission, who is refused, the menu entries
| and the queries. The rules are DocumentsTest's.
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
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function documentsScreenEditor(array $permissions = [ContentPermissions::PAGE_MANAGE, ContentPermissions::BLOG_MANAGE, ContentPermissions::PROJECT_MANAGE], array $stores = ['sa']): AdminBrowser
{
    return Screen::as(Fx::staffWith($permissions, $stores, RoleLevel::Admin));
}

/**
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function documentsScreenFields(array $with = []): array
{
    return [
        'store' => Fx::storeId('sa'), 'version' => 0, 'title_ar' => 'من نحن', 'title_en' => 'About us', 'slug_ar' => '', 'slug_en' => '',
        'body_ar' => "# قصتنا\n\nنصنع **الخشب** منذ عشرين سنة.", 'body_en' => "# Our story\n\nWe have made **wood** for twenty years.", 'intent' => 'draft', ...$with,
    ];
}

function documentsScreenId(string $table): string
{
    return (string) DB::table($table)->orderByDesc('updated_at')->value('id');
}

it('lists pages by tab with their counts, and saves a draft, then publishes it', function () {
    $browser = documentsScreenEditor();

    $saved = $browser->post('/admin/content/pages', documentsScreenFields(), Screen::fromPage('/admin/content/pages/new'));
    $saved->assertRedirect('/admin/content/pages?store=sa');
    $page = documentsScreenId('content.pages');
    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_documents.page.saved'))
        ->and(DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'en')->value('slug'))->toBe('about-us');

    $browser->get('/admin/content/pages?store=sa&tab=draft')->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->component('Content/Admin/Pages/Index')
        ->where('tab', 'draft')
        ->where('counts.DRAFT', 1)
        ->where('counts.PUBLISHED', 0)
        ->has('rows', 1)
        ->where('rows.0.addressAr', '/pages/من-نحن')
        // "By" is the staff member's name, never their id.
        ->where('rows.0.changedBy', fn (?string $name): bool => is_string($name) && str_contains($name, (string) DB::table('access.staff_users')->where('id', DB::table('content.pages')->value('updated_by'))->value('first_name')))
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_pages.title') && $words->has('content::admin_documents.tabs.draft')));
    expect(array_keys($browser->get('/admin/content/pages?store=sa', Screen::JSON)->json()))->toBe(['rows', 'next']);

    $browser->get("/admin/content/pages/{$page}")->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->component('Content/Admin/Pages/Form')
        ->where('kind', 'page')
        ->where('document.ar.body', "# قصتنا\n\nنصنع **الخشب** منذ عشرين سنة.")
        ->where('document.translationUnread', false)
        ->where('pathPrefix', '/pages/')
        ->where('shopAddress', url('/sa')));

    $published = $browser->post("/admin/content/pages/{$page}", documentsScreenFields(['version' => 1, 'intent' => 'publish']), Screen::fromPage("/admin/content/pages/{$page}"));
    expect(AdminBrowser::flashed($published, 'status'))->toBe(__('content::admin_documents.page.published'))
        ->and(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('PUBLISHED');
});

it('keeps Translate\'s mark from a draft\'s save and asks for the tick at Publish, in a later visit too (P14)', function () {
    $browser = documentsScreenEditor();
    $browser->post('/admin/content/pages', documentsScreenFields(['translated' => '1']), Screen::fromPage('/admin/content/pages/new'));
    $page = documentsScreenId('content.pages');

    // A later visit: the form opens marked, and Publish without the tick is refused at the top of the form.
    $browser->get("/admin/content/pages/{$page}")->assertInertia(fn (AssertableInertia $screen) => $screen->where('document.translationUnread', true));
    $refused = $browser->post("/admin/content/pages/{$page}", documentsScreenFields(['version' => 1, 'intent' => 'publish']), Screen::fromPage("/admin/content/pages/{$page}"));
    $refused->assertRedirect("/admin/content/pages/{$page}");
    expect(Screen::errors($refused))->toHaveKey('form')
        ->and(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('DRAFT');

    $browser->post("/admin/content/pages/{$page}", documentsScreenFields(['version' => 2, 'intent' => 'publish', 'translated' => '1', 'read' => '1']), Screen::fromPage("/admin/content/pages/{$page}"));
    expect(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('PUBLISHED')
        ->and(DB::table('content.unread_translations')->count())->toBe(0);
});

it('refuses a box under it, and a published page\'s English blanked', function () {
    $browser = documentsScreenEditor();

    expect(Screen::errors($browser->post('/admin/content/pages', documentsScreenFields(['title_ar' => '']), Screen::fromPage('/admin/content/pages/new'))))->toHaveKey('title_ar')
        ->and(Screen::errors($browser->post('/admin/content/pages', documentsScreenFields(['body_ar' => '[x](javascript:alert(1))']), Screen::fromPage('/admin/content/pages/new'))))->toHaveKey('body_ar')
        ->and(Screen::errors($browser->post('/admin/content/pages', documentsScreenFields(['body_ar' => '![صورة](image:4)']), Screen::fromPage('/admin/content/pages/new'))))->toHaveKey('body_ar');

    $browser->post('/admin/content/pages', documentsScreenFields(['intent' => 'publish']), Screen::fromPage('/admin/content/pages/new'));
    $page = documentsScreenId('content.pages');

    expect(Screen::errors($browser->post("/admin/content/pages/{$page}", documentsScreenFields(['version' => 2, 'title_en' => '', 'body_en' => '']), Screen::fromPage("/admin/content/pages/{$page}"))))->toHaveKey('title_en');
});

it('unpublishes a page, names where links point at it, and deletes it as a draft', function () {
    $browser = documentsScreenEditor();
    $browser->post('/admin/content/pages', documentsScreenFields(['intent' => 'publish']), Screen::fromPage('/admin/content/pages/new'));
    $page = documentsScreenId('content.pages');
    DB::table('content.bars')->updateOrInsert(['store_id' => Fx::storeId('sa')], ['text_ar' => 'اقرأ', 'text_en' => 'Read', 'link' => '/pages/about-us', 'is_on' => false, 'version' => 1, 'updated_at' => CarbonImmutable::now()]);

    $browser->get("/admin/content/pages/{$page}")->assertInertia(fn (AssertableInertia $screen) => $screen->where('linkedFrom', ['bar' => 1]));
    expect(Screen::errors($browser->post("/admin/content/pages/{$page}/delete", [], Screen::fromPage("/admin/content/pages/{$page}"))))->toHaveKey('form');

    $browser->post("/admin/content/pages/{$page}/unpublish", ['version' => 2], Screen::fromPage("/admin/content/pages/{$page}"))->assertRedirect();
    expect(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('DRAFT');

    $browser->post("/admin/content/pages/{$page}/delete", [], Screen::fromPage("/admin/content/pages/{$page}"))->assertRedirect('/admin/content/pages?store=sa');
    expect(DB::table('content.pages')->count())->toBe(0);
});

it('adds, renames, orders and deletes the blog\'s categories, refusing one that holds posts', function () {
    $browser = documentsScreenEditor();
    $category = fn (string $ar, string $en) => $browser->post('/admin/content/blog/categories', ['store' => Fx::storeId('sa'), 'name_ar' => $ar, 'name_en' => $en], Screen::fromPage('/admin/content/blog/categories'));

    $category('نصائح', 'Tips')->assertRedirect();
    $category('أخبار', 'News')->assertRedirect();
    expect(Screen::errors($category('مشاريع', '')))->toHaveKey('name_en');
    [$tips, $news] = DB::table('content.blog_categories')->orderBy('position')->pluck('id')->map(strval(...))->all();

    $browser->post('/admin/content/blog/categories/order', ['store' => Fx::storeId('sa'), 'ids' => [$news, $tips]], Screen::fromPage('/admin/content/blog/categories'))->assertRedirect();
    $browser->post("/admin/content/blog/categories/{$tips}", ['store' => Fx::storeId('sa'), 'name_ar' => 'نصائح', 'name_en' => 'Advice'], Screen::fromPage('/admin/content/blog/categories'))->assertRedirect();

    $browser->get('/admin/content/blog/categories?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->component('Content/Admin/Blog/Categories')
        ->where('categories.0.id', $news)
        ->where('categories.1.nameEn', 'Advice')
        ->where('postsCount', 0));

    $browser->post('/admin/content/blog/posts', documentsScreenFields(['category_id' => $tips]), Screen::fromPage('/admin/content/blog/posts/new'))->assertRedirect('/admin/content/blog?store=sa');
    expect(Screen::errors($browser->post("/admin/content/blog/categories/{$tips}/delete", [], Screen::fromPage('/admin/content/blog/categories'))))->toHaveKey('form');

    $browser->post("/admin/content/blog/categories/{$news}/delete", [], Screen::fromPage('/admin/content/blog/categories'))->assertRedirect();
    expect(DB::table('content.blog_categories')->pluck('id')->all())->toBe([$tips]);

    $browser->get("/admin/content/blog?store=sa&category={$tips}")->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->component('Content/Admin/Blog/Index')
        ->has('rows', 1)
        ->where('category', $tips)
        ->where('rows.0.categoryId', $tips));
});

it('publishes a post with its summary, cover and products, found by the product search', function () {
    $browser = documentsScreenEditor();
    $ready = Px::ready()['product'];
    Px::onSale('sa', $ready);
    Pf::switchOn('sa', productId: $ready);
    $draft = Px::product('Unfinished');

    $found = $browser->get('/admin/content/blog/products?store='.Fx::storeId('sa').'&q=Drawer', Screen::JSON)->assertOk()->json('products');
    expect(array_column($found, 'id'))->toContain(strtolower($ready))
        ->and(in_array(strtolower($draft), array_column($found, 'id'), true))->toBeFalse();

    $cover = Cx::image();
    $browser->post('/admin/content/blog/posts', documentsScreenFields([
        'intent' => 'publish', 'summary_ar' => 'ملخص', 'summary_en' => 'Summary', 'cover_media_id' => $cover, 'cover_alt_ar' => 'خزانة', 'cover_alt_en' => 'A cabinet', 'products' => [$ready],
    ]), Screen::fromPage('/admin/content/blog/posts/new'))->assertRedirect('/admin/content/blog?store=sa');
    $post = documentsScreenId('content.blog_posts');

    expect(DB::table('content.blog_posts')->where('id', $post)->value('status'))->toBe('PUBLISHED');
    $browser->get("/admin/content/blog/posts/{$post}")->assertOk()->assertInertia(fn (AssertableInertia $screen) => $screen
        ->component('Content/Admin/Blog/Form')
        ->where('products.0.id', strtolower($ready))
        ->where('products.0.archived', false)
        ->has("images.{$cover}"));
});

it('searches the store\'s own products 20 at a time, and never links another store\'s by its id (the #386 review)', function () {
    $browser = documentsScreenEditor();
    $ours = Px::ready()['product'];
    Pf::switchOn('sa', productId: $ours);
    $theirs = Px::ready()['product'];
    Pf::switchOn('ae', productId: $theirs);

    $found = $browser->get('/admin/content/blog/products?store='.Fx::storeId('sa').'&q=Drawer', Screen::JSON)->assertOk();
    expect(array_column($found->json('products'), 'id'))->toBe([strtolower($ours)])
        ->and($found->json())->toHaveKey('next');

    // Typed in by its id, another store's product is refused under its box; the store's own is linked.
    $refused = $browser->post('/admin/content/blog/posts', documentsScreenFields(['summary_ar' => 'ملخص', 'summary_en' => 'Summary', 'products' => [$theirs]]), Screen::fromPage('/admin/content/blog/posts/new'));
    expect(Screen::errors($refused))->toHaveKey('products')
        ->and(DB::table('content.blog_posts')->count())->toBe(0);

    // A page further: 20 a page, Show More's cursor the next.
    foreach (range(1, 20) as $n) {
        Pf::switchOn('sa', productId: Px::ready()['product']);
    }

    $first = $browser->get('/admin/content/blog/products?store='.Fx::storeId('sa').'&q=Drawer', Screen::JSON)->assertOk();
    $more = $browser->get('/admin/content/blog/products?store='.Fx::storeId('sa').'&q=Drawer&after='.urlencode((string) $first->json('next')), Screen::JSON)->assertOk();

    expect($first->json('products'))->toHaveCount(20)
        ->and($first->json('next'))->toBeString()
        ->and($more->json('products'))->toHaveCount(1)
        ->and(array_intersect(array_column($first->json('products'), 'id'), array_column($more->json('products'), 'id')))->toBe([]);
});

it('refuses a write to another store\'s item, and leaves it as it was (the #386 review)', function () {
    $sa = documentsScreenEditor([ContentPermissions::PAGE_MANAGE]);
    $sa->post('/admin/content/pages', documentsScreenFields(), Screen::fromPage('/admin/content/pages/new'))->assertRedirect();
    $page = documentsScreenId('content.pages');
    $ae = documentsScreenEditor([ContentPermissions::PAGE_MANAGE], ['ae']);

    $changed = $ae->post("/admin/content/pages/{$page}", documentsScreenFields(['store' => Fx::storeId('ae'), 'version' => 1, 'title_ar' => 'ليس لك']), Screen::fromPage("/admin/content/pages/{$page}"));
    $deleted = $ae->post("/admin/content/pages/{$page}/delete", [], Screen::fromPage("/admin/content/pages/{$page}"));

    expect(Screen::errors($changed))->toHaveKey('form')
        // A delete names the item before it is refused: another store's is not found, as a page they can't open.
        ->and($deleted->getStatusCode())->toBe(404)
        ->and(DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'ar')->value('title'))->toBe('من نحن')
        ->and(DB::table('content.pages')->where('id', $page)->exists())->toBeTrue();
});

it('keeps a project\'s photos in their order, and says the Projects page stays hidden while none is published', function () {
    $browser = documentsScreenEditor();
    [$one, $two] = [Cx::image(), Cx::image()];

    $browser->get('/admin/content/projects?store=sa')->assertInertia(fn (AssertableInertia $screen) => $screen->component('Content/Admin/Projects/Index')->where('counts.PUBLISHED', 0));
    $browser->post('/admin/content/projects', documentsScreenFields(['intent' => 'publish', 'city_ar' => 'الرياض', 'city_en' => 'Riyadh', 'photos' => [$two, $one]]), Screen::fromPage('/admin/content/projects/new'))->assertRedirect('/admin/content/projects?store=sa');

    expect(DB::table('content.project_photos')->orderBy('position')->pluck('media_id')->all())->toBe([$two, $one])
        ->and(DB::table('content.projects')->value('status'))->toBe('PUBLISHED');
    expect(Screen::errors($browser->post('/admin/content/projects', documentsScreenFields(['intent' => 'publish', 'title_ar' => 'بلا صور', 'title_en' => 'No photos']), Screen::fromPage('/admin/content/projects/new'))))->toHaveKey('photos');
});

it('uploads an image under the kind\'s own permission only', function () {
    Storage::fake('local');
    Storage::fake('public');
    $file = fn () => UploadedFile::fake()->image('photo.jpg', 800, 600);

    foreach (['page' => ContentPermissions::PAGE_MANAGE, 'blog' => ContentPermissions::BLOG_MANAGE, 'project' => ContentPermissions::PROJECT_MANAGE] as $kind => $permission) {
        documentsScreenEditor([$permission])->post("/admin/content/uploads/{$kind}", ['store' => Fx::storeId('sa'), 'file' => $file()], Screen::JSON)->assertCreated();
        documentsScreenEditor([ContentPermissions::SEO_MANAGE])->post("/admin/content/uploads/{$kind}", ['store' => Fx::storeId('sa'), 'file' => $file()], Screen::JSON)->assertForbidden();
    }
});

it('refuses each screen without its job, and another store\'s item as unknown', function () {
    $pages = documentsScreenEditor([ContentPermissions::PAGE_MANAGE]);
    $pages->post('/admin/content/pages', documentsScreenFields(), Screen::fromPage('/admin/content/pages/new'));
    $page = documentsScreenId('content.pages');

    $pages->get('/admin/content/blog?store=sa')->assertForbidden();
    $pages->get('/admin/content/projects?store=sa')->assertForbidden();
    documentsScreenEditor([ContentPermissions::BLOG_MANAGE])->get('/admin/content/pages?store=sa')->assertForbidden();
    documentsScreenEditor([ContentPermissions::PAGE_MANAGE], ['ae'])->get("/admin/content/pages/{$page}")->assertNotFound();
});

it('offers the menu entries only to whoever may use them', function () {
    expect(Screen::menu(documentsScreenEditor([ContentPermissions::PAGE_MANAGE])))->toBe(['pages'])
        ->and(Screen::menu(documentsScreenEditor([ContentPermissions::BLOG_MANAGE])))->toBe(['blog'])
        ->and(Screen::menu(documentsScreenEditor([ContentPermissions::PROJECT_MANAGE])))->toBe(['projects']);
});

it('costs few queries of its own', function () {
    $browser = documentsScreenEditor();
    $browser->post('/admin/content/pages', documentsScreenFields(), Screen::fromPage('/admin/content/pages/new'));
    $browser->post('/admin/content/blog/posts', documentsScreenFields(), Screen::fromPage('/admin/content/blog/posts/new'));
    $browser->post('/admin/content/projects', documentsScreenFields(), Screen::fromPage('/admin/content/projects/new'));
    [$page, $post, $project] = [documentsScreenId('content.pages'), documentsScreenId('content.blog_posts'), documentsScreenId('content.projects')];

    // Measured 2026-11-11: the panel's frame's 10 (AdminFrameQueryBudgetTest) and the page's own - a list 3 or 4 (its rows,
    // the tabs' counts, the names of who changed them; the blog's categories), a form 9 to 13 (the item, its marks, its
    // address history and Content's links to it, its files, its products, the blog's categories). Content's target is 15 of
    // its own (§4.2).
    expect([
        Screen::queries($browser, '/admin/content/pages?store=sa&tab=draft'),
        Screen::queries($browser, "/admin/content/pages/{$page}"),
        Screen::queries($browser, '/admin/content/blog?store=sa'),
        Screen::queries($browser, "/admin/content/blog/posts/{$post}"),
        Screen::queries($browser, '/admin/content/blog/categories?store=sa'),
        Screen::queries($browser, '/admin/content/projects?store=sa&tab=draft'),
        Screen::queries($browser, "/admin/content/projects/{$project}"),
    ])->toBe([13, 19, 14, 22, 14, 13, 23]);
});
