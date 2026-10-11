<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProducts;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProductsHandler;
use Modules\Catalog\Domain\Exception\ImportUndecided;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's products import over HTTP (catalog.md §4.4 S11, §1.12): the Super Admin's alone - the
| files, uploading one and a refused file's problems, an import's four parts as pages of their own,
| every decision and change said where the panel says it, bringing in, accepting, archiving,
| discarding -, what still waits counted as Bring Products In counts it, the files' guide and its
| examples for whoever fills a file, and each page's own queries, recorded.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

afterEach(function () {
    Ix::cleanUp();
});

/** Signed in through the panel's door as this staff member. */
function catalogImportScreensAs(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.4.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

function catalogImportScreensSuper(): AdminBrowser
{
    return catalogImportScreensAs(Fx::staff(superAdmin: true));
}

/**
 * A products file as the Super Admin uploads it; its import's id, from where its page opened.
 *
 * @param  list<array<string, mixed>>  $products
 * @return TestResponse<Response>
 */
function catalogImportScreensUpload(AdminBrowser $browser, array $products): TestResponse
{
    return $browser->post('/admin/imports', ['file' => UploadedFile::fake()->createWithContent('products.json', Ix::json($products))]);
}

/**
 * @param  TestResponse<Response>  $response
 */
function catalogImportScreensId(TestResponse $response): string
{
    return basename((string) $response->headers->get('Location'));
}

/**
 * @param  TestResponse<Response>  $response
 */
function catalogImportScreensToast(TestResponse $response): mixed
{
    return AdminBrowser::flashed($response, 'status');
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function catalogImportScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/** How many queries a page asks for itself, opened a second time (warm) - the panel's frame apart. */
function catalogImportScreensOwnQueries(AdminBrowser $browser, string $uri): int
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

describe('the files', function () {
    it('lists the Super Admin\'s files newest first, who uploaded each and its state, and opens for no one else', function () {
        $super = catalogImportScreensSuper();
        $first = catalogImportScreensUpload($super, [Ix::product('880001')]);
        $second = catalogImportScreensUpload($super, [Ix::product('880002'), Ix::product('880003')]);

        expect(catalogImportScreensToast($first))->toBe('File uploaded')
            ->and((string) $first->headers->get('Location'))->toContain('/admin/imports/');

        $super->get('/admin/imports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/Imports/Index')
            ->where('files.0.id', catalogImportScreensId($second))
            ->where('files.0.products', 2)
            ->where('files.0.state', 'DECIDING')
            ->whereType('files.0.uploadedBy', 'string')
            ->where('files.1.id', catalogImportScreensId($first))
            ->where('problems', null));

        $admin = catalogImportScreensAs(Fx::staffWith([P::LISTING_FILL, P::PRODUCT_VIEW, P::PRODUCT_CREATE], ['*'], RoleLevel::Admin));
        $admin->get('/admin/imports')->assertForbidden();
        $admin->get('/admin/imports/'.catalogImportScreensId($first))->assertForbidden();
    });

    it('lists every problem of a refused file under the upload, once, and keeps nothing of it', function () {
        $super = catalogImportScreensSuper();
        $refused = $super->post('/admin/imports', ['file' => UploadedFile::fake()->createWithContent('products.json', json_encode(['format' => 'touchwood-products/1', 'products' => [['name' => ['ar' => '', 'en' => 'Odd'], 'variants' => [['code' => 'abc']]]]], JSON_THROW_ON_ERROR))]);

        expect(catalogImportScreensErrors($refused))->toHaveKey('file')
            ->and(DB::table('catalog.imports')->where('kind', 'PRODUCTS')->count())->toBe(0);

        $super->get('/admin/imports')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('problems', fn (Collection $problems): bool => $problems->isNotEmpty())
            ->whereType('problems.0.at', 'string')
            ->whereType('problemsTotal', 'integer'));
        $super->get('/admin/imports')->assertInertia(fn (AssertableInertia $page) => $page->where('problems', null));
    });
});

describe('an import\'s parts', function () {
    it('opens each part as a page of its own, and decides a name the catalog lacks', function () {
        $brand = Px::brand('Blum');
        $super = catalogImportScreensSuper();
        $import = catalogImportScreensId(catalogImportScreensUpload($super, [Ix::product('880101', ['brand' => 'Blumm'])]));
        $nameId = Ix::nameId($import, 'BRAND', 'Blumm');

        $super->get("/admin/imports/{$import}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/Imports/NamesPart')
            ->where('part', 'names')
            ->where('waiting.names', 1)
            ->where('names.0.kind', 'BRAND')
            ->where('names.0.written', 'Blumm')
            ->where('names.0.decision', null)
            ->where('brands', fn (Collection $brands): bool => $brands->contains(fn (array $option): bool => $option['id'] === $brand)));

        foreach (['codes' => 'CodesPart', 'before' => 'BeforePart', 'products' => 'ProductsPart'] as $part => $component) {
            $super->get("/admin/imports/{$import}?part={$part}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component("Catalog/Admin/Imports/{$component}")->where('part', $part)->where('names', null));
        }

        expect(catalogImportScreensToast($super->post("/admin/imports/{$import}/names", ['decisions' => [['name_id' => $nameId, 'decision' => 'EXISTING', 'target_id' => $brand]]])))->toBe('Decision saved')
            ->and(DB::table('catalog.import_names')->where('id', $nameId)->value('decision'))->toBe('EXISTING');

        $super->get("/admin/imports/{$import}")->assertInertia(fn (AssertableInertia $page) => $page->where('waiting.names', 0)->where('names.0.targetId', $brand));
    });

    it('decides a product whose code the catalog holds, naming the catalog\'s product, and changes the products before bringing them in', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $variant)->value('code');
        $brand = Px::brand('Hettich');
        $super = catalogImportScreensSuper();
        $import = catalogImportScreensId(catalogImportScreensUpload($super, [Ix::product($code), Ix::product('880201')]));
        $row = Ix::productId($import, 1);

        $super->get("/admin/imports/{$import}?part=codes")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 1)
            ->where('products.0.conflictProductId', $product)
            ->where('products.0.conflictNameEn', DB::table('catalog.products')->where('id', $product)->value('name_en'))
            ->where('waiting.codes', 1));

        expect(catalogImportScreensToast($super->post("/admin/imports/{$import}/codes", ['decisions' => [['product_id' => $row, 'decision' => 'SKIP', 'sale' => null]]])))->toBe('Decision saved')
            ->and(DB::table('catalog.import_products')->where('id', $row)->value('decision'))->toBe('SKIP')
            ->and(catalogImportScreensToast($super->post("/admin/imports/{$import}/brand", ['brand_id' => $brand, 'mode' => 'REPLACE'])))->toBe('Products changed')
            ->and(catalogImportScreensErrors($super->post("/admin/imports/{$import}/brand", ['brand_id' => '', 'mode' => 'REPLACE'])))->not->toBe([]);
    });

    it('counts what still waits exactly as Bring Products In does', function () {
        ['variants' => [$variant]] = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $variant)->value('code');
        $super = catalogImportScreensSuper();
        $import = catalogImportScreensId(catalogImportScreensUpload($super, [Ix::product($code), Ix::product('880301', ['brand' => 'Nobody', 'warranty' => 'Forever and a day'])]));
        $waiting = null;

        $super->get("/admin/imports/{$import}")->assertInertia(function (AssertableInertia $page) use (&$waiting): void {
            $waiting = $page->toArray()['props']['waiting'];
        });

        Fx::actAsStaff(Fx::staff(superAdmin: true));

        try {
            app(BringInImportHandler::class)->handle(new BringInImport($import));
            $refused = null;
        } catch (ImportUndecided $undecided) {
            $refused = ['names' => $undecided->names, 'codes' => $undecided->codes, 'addresses' => $undecided->addresses, 'sales' => $undecided->sales, 'codeChanges' => $undecided->codeChanges, 'attributeChanges' => $undecided->attributeChanges];
        }

        expect($refused)->not->toBeNull()
            ->and($waiting)->toBe($refused)
            ->and(AdminBrowser::formError($super->post("/admin/imports/{$import}/bring-in")))->not->toBeEmpty();
    });

    it('brings the products in, then accepts, archives and discards as the Super Admin says', function () {
        $super = catalogImportScreensSuper();
        $import = catalogImportScreensId(catalogImportScreensUpload($super, [Ix::product('880401'), Ix::product('880402')]));

        expect(catalogImportScreensToast($super->post("/admin/imports/{$import}/bring-in")))->toBe('Bringing the products in')
            ->and(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('BRINGING_IN');

        $super->get("/admin/imports/{$import}")->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Admin/Imports/ProductsPart')->where('import.state', 'BRINGING_IN'));

        Fx::asSystem(fn () => app(BringInImportProductsHandler::class)->handle(new BringInImportProducts($import)));
        $first = Ix::productId($import, 1);

        $super->get("/admin/imports/{$import}?part=products")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('import.state', 'IN')
            ->has('products', 2)
            ->where('products.0.state', 'IN')
            ->where('products.0.missing', fn (Collection $missing): bool => $missing->contains('category')));

        expect(catalogImportScreensToast($super->post("/admin/imports/{$import}/archive", ['product_ids' => [$first]])))->toBe('Products archived')
            ->and(DB::table('catalog.import_products')->where('id', $first)->value('state'))->toBe('ARCHIVED');

        $discarded = catalogImportScreensId(catalogImportScreensUpload($super, [Ix::product('880403')]));
        $response = $super->post("/admin/imports/{$discarded}/discard");

        expect($response->headers->get('Location'))->toEndWith('/admin/imports')
            ->and(catalogImportScreensToast($response))->toBe('Import discarded')
            ->and(DB::table('catalog.imports')->where('id', $discarded)->exists())->toBeFalse();
    });

    it('shows the products a hundred at a time, and opens each part in its own recorded number of queries, however many products', function () {
        $super = catalogImportScreensSuper();
        $few = catalogImportScreensId(catalogImportScreensUpload($super, array_map(static fn (int $n): array => Ix::product((string) (881000 + $n)), range(1, 3))));
        $many = catalogImportScreensId(catalogImportScreensUpload($super, array_map(static fn (int $n): array => Ix::product((string) (882000 + $n)), range(1, 120))));

        $super->get("/admin/imports/{$many}?part=products")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 100)->where('more', true)->where('page', 1));
        $super->get("/admin/imports/{$many}?part=products&page=2")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 20)->where('more', false));

        $counts = [];

        foreach (['names', 'codes', 'before', 'products'] as $part) {
            $own = catalogImportScreensOwnQueries($super, "/admin/imports/{$few}?part={$part}");

            expect(catalogImportScreensOwnQueries($super, "/admin/imports/{$many}?part={$part}"))->toBe($own)
                ->and($own)->toBeLessThanOrEqual(15);
            $counts[$part] = $own;
        }

        // Each the same for 3 products and for 120: none is read a product at a time (P13).
        expect($counts)->toBe(['names' => 8, 'codes' => 6, 'before' => 8, 'products' => 6])
            ->and(catalogImportScreensOwnQueries($super, '/admin/imports'))->toBe(3);
    });
});

describe('the files\' guide', function () {
    it('shows the guide and its examples to whoever fills a file, and to no one else', function () {
        $super = catalogImportScreensSuper();

        $super->get('/admin/import-guide')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/ImportGuide')
            ->where('html', fn (string $html): bool => str_contains($html, 'href="/admin/import-guide/products"') && ! str_contains($html, '<script')));
        $super->get('/admin/import-guide/products')->assertOk()->assertDownload('products.example.json');

        catalogImportScreensAs(Fx::staffWith([P::LISTING_FILL, P::PRODUCT_VIEW], ['sa'], RoleLevel::Admin))->get('/admin/import-guide/store-fill')->assertOk();
        catalogImportScreensAs(Fx::staffWith([P::PRODUCT_VIEW], ['sa']))->get('/admin/import-guide')->assertForbidden();
    });
});
