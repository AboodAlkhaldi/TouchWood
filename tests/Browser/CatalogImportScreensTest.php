<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\UploadImport\UploadImport;
use Modules\Catalog\Application\Command\UploadImport\UploadImportHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Catalog's products import, in a real browser (catalog.md §4.4 S11).
|
| The feature tests beside them prove what each part is handed and what the server does with what it
| sends. These prove the Super Admin can use them: a name the catalog lacks refused from its row, the
| products brought in once nothing waits, the parts moved between by their tabs; and every page of
| the import - the files, its parts, the guide - right to left in Arabic at a phone's width.
|
| No RefreshDatabase, for the reason written at the top of StaffScreensTest: every name and code here
| is made unique from the clock, as the rows stay. A file is not chosen here - the plugin's server
| receives no file (lesson 117); the import is made through its use case, and the feature tests
| upload them.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    // Bringing in puts a product with no brand on the default one, which this seed makes.
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** The Super Admin, signed in through the panel's door. */
function catalogImportBrowser(string $locale = 'en'): mixed
{
    $staffId = Fx::staff(superAdmin: true);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

/** What a read gives once a save has landed, waited through the page (CatalogListScreensTest). */
function catalogImportBrowserSoon(mixed $page, Closure $read, Closure $holds): mixed
{
    $value = $read();

    for ($tries = 0; $tries < 50 && ! $holds($value); $tries++) {
        $page->wait(0.1);
        $value = $read();
    }

    return $value;
}

/**
 * A products file of one product naming a brand the catalog lacks, made through its use case.
 *
 * @return array{import: string, n: string}
 */
function catalogImportBrowserFile(): array
{
    $n = substr((string) hrtime(true), -12);
    $json = json_encode(['format' => 'touchwood-products/1', 'products' => [[
        'name' => ['ar' => "منتج {$n}", 'en' => "Product {$n}"],
        'brand' => "Nobody {$n}",
        'variants' => [['code' => substr($n, -9)]],
    ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $path = (string) tempnam(sys_get_temp_dir(), 'tw-');
    file_put_contents($path, $json);
    $import = Fx::asSystem(fn (): string => app(UploadImportHandler::class)->handle(new UploadImport($path, "products {$n}.json")));
    @unlink($path);

    return ['import' => $import, 'n' => $n];
}

it('refuses a name from its row, brings the products in once nothing waits, and moves between the parts', function () {
    ['import' => $import] = catalogImportBrowserFile();
    $nameId = (string) DB::table('catalog.import_names')->where('import_id', $import)->value('id');
    $page = catalogImportBrowser();
    $page->navigate("/admin/imports/{$import}", BROWSER_PAGE_LOAD);

    expect(browserUntil($page, "document.querySelector('[data-test=\"waiting\"]') !== null"))->toBeTrue();

    $page->click("[data-test=\"name-{$nameId}\"] [data-test=\"refuse\"]")
        ->click('[data-test="confirm-refuse"]');

    expect(catalogImportBrowserSoon($page, fn (): mixed => DB::table('catalog.import_names')->where('id', $nameId)->value('decision'), fn ($found): bool => $found === 'REFUSE'))->toBe('REFUSE')
        ->and(browserUntil($page, "document.querySelector('[data-test=\"waiting\"]') === null"))->toBeTrue();

    $page->click('[data-test="bring-in"]');

    expect(catalogImportBrowserSoon($page, fn (): mixed => DB::table('catalog.imports')->where('id', $import)->value('state'), fn ($found): bool => $found === 'IN'))->toBe('IN');

    // A brand refused leaves the product on the default brand (§1.12): it comes in.
    $page->click('[data-test="part-products"]');

    expect(browserUntil($page, "window.location.search.includes('part=products') && document.querySelector('[data-test=\"product-row-1\"]')?.getAttribute('data-state') === 'IN'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('reads right to left in Arabic, at a phone\'s width, with nothing wider than the screen', function () {
    ['import' => $import] = catalogImportBrowserFile();
    $page = catalogImportBrowser('ar');
    $page->resize(375, 812);

    foreach (['/admin/imports' => "import-{$import}", "/admin/imports/{$import}" => 'names-BRAND', "/admin/imports/{$import}?part=before" => 'addresses-empty', "/admin/imports/{$import}?part=products" => 'product-row-1', '/admin/import-guide' => 'import-guide-text'] as $address => $shown) {
        $page->navigate($address, BROWSER_PAGE_LOAD);

        expect(browserUntil($page, "document.querySelector('[data-test=\"{$shown}\"]') !== null"))->toBeTrue()
            ->and($page->script('document.documentElement.dir'))->toBe('rtl')
            ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    }

    $page->assertNoJavaScriptErrors();
});
