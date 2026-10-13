<?php

declare(strict_types=1);

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
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabels;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabelsHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabel;
use Modules\Catalog\Application\Command\DeactivateLabel\DeactivateLabelHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's store screens over HTTP (catalog.md §4.4 S9 "Stores", S10, P6): a product's Stores tab - a
| card for each store the reader covers, a Super Admin's off stores too -, what each of its controls
| changes in one store under its own job, read only to anyone else; and each page's own queries,
| recorded under the admin budget of 15.
|
| The use cases have their own tests; these are about the screens.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

/** Signed in through the panel's door as this staff member. */
function catalogStoreScreensAs(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.3.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * Signed in with these jobs ('*' for All stores).
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function catalogStoreScreens(array $permissions, array $stores = ['*']): AdminBrowser
{
    return catalogStoreScreensAs(Fx::staffWith($permissions, $stores));
}

/**
 * @param  TestResponse<Response>  $response
 */
function catalogStoreScreensToast(TestResponse $response): mixed
{
    return AdminBrowser::flashed($response, 'status');
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function catalogStoreScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

function catalogStoreScreensOn(string $store, string $productId): void
{
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $productId, true)));
}

/** A label - its names one or two words, as labels are. */
function catalogStoreScreensLabel(string $nameEn): string
{
    return Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel("وسم {$nameEn}", $nameEn, 'green')));
}

/**
 * A store's card on the tab, by its code.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>|null
 */
function catalogStoreScreensCard(TestResponse $response, string $code): ?array
{
    $card = null;

    $response->assertInertia(function (AssertableInertia $page) use (&$card, $code): void {
        foreach ($page->toArray()['props']['stores'] ?? [] as $store) {
            if ($store['code'] === $code) {
                $card = $store;
            }
        }
    });

    return $card;
}

/**
 * One field of each variant row on a store's card.
 *
 * @param  array<string, mixed>|null  $card
 * @return array<string, mixed> variant id => the field
 */
function catalogStoreScreensVariants(?array $card, string $field): array
{
    $values = [];
    $rows = $card['variants'] ?? null;

    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && is_string($row['variantId'] ?? null)) {
            $values[$row['variantId']] = $row[$field] ?? null;
        }
    }

    return $values;
}

/**
 * How many queries the page asks for itself, opened a second time (warm): those made while Catalog's
 * own code is running - the panel's frame around it is counted apart (CatalogListScreensTest).
 */
function catalogStoreScreensOwnQueries(AdminBrowser $browser, string $uri): int
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

describe('a product\'s Stores tab', function () {
    it('shows a card for each store the reader covers, what it does there, and what the reader may do', function () {
        ['product' => $product, 'variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        catalogStoreScreensOn('eg', $product);
        $label = catalogStoreScreensLabel('New');
        DB::table('catalog.store_product_labels')->insert(['store_id' => Fx::storeId('eg'), 'product_id' => $product, 'label_id' => $label]);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE], ['eg', 'sa']);

        $opened = $browser->get("/admin/products/{$product}?tab=stores");
        $opened->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/Products/StoresTab')
            ->where('tab', 'stores')
            ->has('stores', 2)
            ->has('variants', 2)
            ->where('labels.0.id', $label)
        );

        $eg = catalogStoreScreensCard($opened, 'eg');
        $sa = catalogStoreScreensCard($opened, 'sa');

        expect($eg)->not->toBeNull()
            ->and([$eg['chosen'] ?? null, $eg['labelIds'] ?? null, $eg['mayChoose'] ?? null, $eg['maySell'] ?? null])->toBe([true, [$label], true, false])
            ->and(catalogStoreScreensVariants($eg, 'on'))->toEqual([$sixty => true, $eighty => true])
            ->and(catalogStoreScreensVariants($eg, 'retail'))->toEqual([$sixty => true, $eighty => true])
            ->and([$sa['chosen'] ?? null, $sa['variants'] ?? null])->toBe([false, []]);
    });

    it('shows a Super Admin every store, the ones that are off marked', function () {
        ['product' => $product] = Px::ready();
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));
        $browser = catalogStoreScreensAs(Fx::staff(superAdmin: true));

        $opened = $browser->get("/admin/products/{$product}?tab=stores");
        $ae = catalogStoreScreensCard($opened, 'ae');

        expect($ae)->not->toBeNull()
            ->and([$ae['active'] ?? null, $ae['mayChoose'] ?? null])->toBe([false, true])
            ->and(catalogStoreScreensCard(catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE])->get("/admin/products/{$product}?tab=stores"), 'ae'))->toBeNull();
    });

    it('switches a ready product on and off in a store, whole or a variant at a time, and never a draft', function () {
        ['product' => $product, 'variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        $draft = Px::product('Hinge');
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE], ['eg']);
        $eg = Fx::storeId('eg');
        $on = fn (): array => DB::table('catalog.store_variants')->where('store_id', $eg)->where('product_id', $product)->pluck('is_active', 'variant_id')->all();

        expect(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/choose", ['active' => true])))->toBe('Switched on in the store')
            ->and($on())->toEqual([$sixty => true, $eighty => true])
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/choose", ['active' => false, 'variant_ids' => [$eighty]])))->toBe('Switched off in the store')
            ->and($on())->toEqual([$sixty => true, $eighty => false])
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$draft}/stores/eg/choose", ['active' => true])))->not->toBeNull()
            ->and(DB::table('catalog.store_products')->where('product_id', $draft)->exists())->toBeFalse();
    });

    it('saves each variant\'s ways of selling with the quantities, and says what they must keep', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_SELLING], ['eg']);
        $eg = Fx::storeId('eg');
        $quantities = ['retail_minimum' => 1, 'retail_maximum' => null, 'wholesale_minimum' => null, 'wholesale_maximum' => null];

        // Wholesale needs a wholesale minimum: refused, nothing changed.
        expect(AdminBrowser::formError($browser->post("/admin/products/{$product}/stores/eg/selling", [...$quantities, 'modes' => [$variant => ['retail' => true, 'wholesale' => true]]])))->not->toBeNull()
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('sells_wholesale'))->toBeFalse()
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/selling", [...$quantities, 'wholesale_minimum' => 10, 'modes' => [$variant => ['retail' => true, 'wholesale' => true]]])))->toBe('Selling terms saved')
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('sells_wholesale'))->toBeTrue()
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->where('store_id', $eg)->value('wholesale_minimum'))->toBe(10)
            // The quantities alone, as the form saves them, keep the ways of selling.
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/selling", ['retail_minimum' => '2', 'retail_maximum' => '50', 'wholesale_minimum' => '10', 'wholesale_maximum' => ''])))->toBe('Selling terms saved')
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->where('store_id', $eg)->first(['retail_minimum', 'retail_maximum']))->toEqual((object) ['retail_minimum' => 2, 'retail_maximum' => 50])
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('sells_wholesale'))->toBeTrue();
    });

    it('marks the whole product or a variant not available now, and available again', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_UNAVAILABLE], ['eg']);
        $eg = Fx::storeId('eg');

        expect(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/unavailable", ['unavailable' => true])))->toBe('Marked not available now')
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->value('not_available_now'))->toBeTrue()
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/unavailable", ['unavailable' => false])))->toBe('Available again')
            ->and(DB::table('catalog.store_products')->where('product_id', $product)->value('not_available_now'))->toBeFalse()
            ->and(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/unavailable", ['unavailable' => true, 'variant_id' => $variant])))->toBe('Marked not available now')
            ->and(DB::table('catalog.store_variants')->where('variant_id', $variant)->value('not_available_now'))->toBeTrue();
    });

    it('puts at most 10 labels on the product in a store', function () {
        ['product' => $product] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        $labels = array_map(fn (int $n): string => catalogStoreScreensLabel("L{$n}"), range(1, 11));
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_LABELS], ['eg']);
        $eg = Fx::storeId('eg');

        expect(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/labels", ['label_ids' => array_slice($labels, 0, 2)])))->toBe('Labels saved')
            ->and(DB::table('catalog.store_product_labels')->where('product_id', $product)->count())->toBe(2)
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$product}/stores/eg/labels", ['label_ids' => $labels])))->not->toBeNull()
            ->and(DB::table('catalog.store_product_labels')->where('product_id', $product)->count())->toBe(2);
    });

    it('is read only in a store where the reader holds none of its jobs, and refuses its changes there', function () {
        ['product' => $product] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        catalogStoreScreensOn('sa', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE, P::LISTING_SELLING, P::LISTING_UNAVAILABLE, P::LISTING_LABELS], ['eg', 'sa']);
        $reader = catalogStoreScreens([P::PRODUCT_VIEW], ['sa']);
        $sa = catalogStoreScreensCard($reader->get("/admin/products/{$product}?tab=stores"), 'sa');
        $saId = Fx::storeId('sa');

        expect([$sa['mayChoose'] ?? null, $sa['maySell'] ?? null, $sa['mayUnavailable'] ?? null, $sa['mayLabel'] ?? null])->toBe([false, false, false, false])
            ->and(catalogStoreScreensCard($browser->get("/admin/products/{$product}?tab=stores"), 'sa')['mayLabel'] ?? null)->toBeTrue()
            ->and(AdminBrowser::formError($reader->post("/admin/products/{$product}/stores/sa/choose", ['active' => false])))->toBe(trans('errors.unauthorized.detail', [], 'en'))
            ->and(DB::table('catalog.store_variants')->where('product_id', $product)->where('store_id', $saId)->value('is_active'))->toBeTrue();
    });
});

/** A store's admin: the store file is admin roles' only (amendment 6(h)). */
function catalogStoreScreensFiller(string $store = 'sa'): AdminBrowser
{
    return catalogStoreScreensAs(Fx::staffWith([P::LISTING_FILL, P::PRODUCT_VIEW], [$store], RoleLevel::Admin));
}

/**
 * A store file as the admin uploads it.
 *
 * @param  list<array<string, mixed>>  $items
 * @return TestResponse<Response>
 */
function catalogStoreScreensUpload(AdminBrowser $browser, array $items, string $store = 'sa'): TestResponse
{
    $json = json_encode(['format' => 'touchwood-store-fill/1', 'items' => $items], JSON_THROW_ON_ERROR);

    return $browser->post('/admin/store-files', ['store' => $store, 'file' => UploadedFile::fake()->createWithContent('prices.json', $json)]);
}

/** The variant code of a ready product's first variant. */
function catalogStoreScreensCode(string $variantId): string
{
    return (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
}

describe('store files (S10)', function () {
    it('lists a store\'s files to its admins, newest first with what is still open, and opens for no one else', function () {
        $admin = catalogStoreScreensFiller();
        $first = catalogStoreScreensUpload($admin, [['code' => '880001', 'price' => 1], ['code' => '880002', 'price' => 2]]);
        $second = catalogStoreScreensUpload($admin, [['code' => '880003', 'price' => 3]]);
        $firstId = basename((string) $first->headers->get('Location'));
        $secondId = basename((string) $second->headers->get('Location'));

        expect(catalogStoreScreensToast($first))->toBe('File uploaded');

        $admin->get('/admin/store-files')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/StoreFiles/Index')
            ->where('storeCode', 'sa')
            ->where('files.0.id', $secondId)
            ->where('files.1.id', $firstId)
            ->where('files.1.items', 2)
            ->where('files.1.open', 2)
            ->where('problems', null)
            ->where('more', false));

        $admin->get('/admin/store-files?store=eg')->assertForbidden();
        catalogStoreScreens([P::LISTING_CHOOSE, P::PRODUCT_VIEW], ['sa'])->get('/admin/store-files')->assertForbidden();
        catalogStoreScreensFiller('eg')->get("/admin/store-files/{$firstId}")->assertNotFound();
    });

    it('lists every problem of a refused file under the upload, and keeps nothing of it', function () {
        $admin = catalogStoreScreensFiller();
        $refused = catalogStoreScreensUpload($admin, [['code' => 'abc'], ['code' => '880001', 'price' => -1]]);

        expect(catalogStoreScreensErrors($refused))->toHaveKey('file')
            ->and(DB::table('catalog.imports')->where('kind', 'STORE_FILL')->count())->toBe(0);

        $admin->get('/admin/store-files')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('problems', 3)
            ->where('problemsTotal', 3)
            ->whereType('problems.0.at', 'string')
            ->whereType('problems.0.problem', 'string'));
        // Said once: the next visit no longer carries them.
        $admin->get('/admin/store-files')->assertInertia(fn (AssertableInertia $page) => $page->where('problems', null));
    });

    it('shows each item as it stands, switches on the selected and every ready one, removes, and corrects a code', function () {
        ['product' => $ready, 'variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        ['product' => $other, 'variants' => [$otherVariant]] = Px::ready();
        $draft = Px::product();
        Px::variant($draft, '7700');
        $admin = catalogStoreScreensFiller();
        $uploaded = catalogStoreScreensUpload($admin, [
            ['code' => catalogStoreScreensCode($sixty), 'price' => 10], ['code' => catalogStoreScreensCode($eighty), 'price' => 12, 'stock' => 4],
            ['code' => '7700', 'price' => 5], ['code' => '999111', 'price' => 6], ['code' => '999222', 'price' => 7],
        ]);
        $file = basename((string) $uploaded->headers->get('Location'));
        $item = fn (int $number): string => (string) DB::table('catalog.store_fill_items')->where('import_id', $file)->where('number', $number)->value('id');
        $state = fn (int $number): string => (string) DB::table('catalog.store_fill_items')->where('import_id', $file)->where('number', $number)->value('state');
        $sa = Fx::storeId('sa');

        $admin->get("/admin/store-files/{$file}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Admin/StoreFiles/Show')
            ->where('storeCode', 'sa')
            // Kept since stage 5 registered its sections (Inventory's stock; Pricing's prices with it).
            ->where('pricesKept', true)
            ->where('items.0.standing', 'READY')
            ->where('items.0.productId', $ready)
            ->where('items.0.productNameEn', DB::table('catalog.products')->where('id', $ready)->value('name_en'))
            ->where('items.1.stock', 4)
            ->where('items.2.standing', 'NOT_READY')
            ->where('items.2.missing', fn ($missing): bool => in_array('photos', is_array($missing) ? $missing : $missing->all(), true))
            ->where('items.3.standing', 'UNKNOWN'));

        expect(catalogStoreScreensToast($admin->post("/admin/store-files/{$file}/switch-on", ['item_ids' => [$item(1)]])))->toBe('Switched on: 1')
            ->and(DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $sixty)->value('is_active'))->toBeTrue()
            ->and($state(2))->toBe('OPEN')
            // An unknown code corrected to a ready product's: checked again, now ready.
            ->and(catalogStoreScreensErrors($admin->post("/admin/store-files/{$file}/items/{$item(4)}/code", ['code' => catalogStoreScreensCode($sixty)])))->toHaveKey('code')
            ->and(catalogStoreScreensToast($admin->post("/admin/store-files/{$file}/items/{$item(4)}/code", ['code' => catalogStoreScreensCode($otherVariant)])))->toBe('Code corrected')
            ->and(catalogStoreScreensToast($admin->post("/admin/store-files/{$file}/remove", ['item_ids' => [$item(5)]])))->toBe('Items removed')
            ->and($state(5))->toBe('REMOVED')
            // Every ready one: 80 cm and the corrected code; the draft's stays open.
            ->and(catalogStoreScreensToast($admin->post("/admin/store-files/{$file}/switch-on")))->toBe('Switched on: 2')
            ->and([$state(1), $state(2), $state(3), $state(4)])->toBe(['ON', 'ON', 'OPEN', 'ON'])
            ->and(DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $otherVariant)->value('is_active'))->toBeTrue()
            ->and(AdminBrowser::formError(catalogStoreScreens([P::LISTING_CHOOSE, P::PRODUCT_VIEW], ['sa'])->post("/admin/store-files/{$file}/switch-on")))->not->toBeEmpty();

        expect(DB::table('catalog.store_products')->where('store_id', $sa)->whereIn('product_id', [$ready, $other])->count())->toBe(2);

        // Switched on or removed, an item still names the product its code finds (the review of step 3).
        $admin->get("/admin/store-files/{$file}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items.0.state', 'ON')
            ->where('items.0.productId', $ready)
            ->where('items.0.standing', null)
            ->where('items.3.productId', $other)
            ->where('items.4.state', 'REMOVED')
            ->where('items.4.productId', null));
    });

    it('fills a store that is switched off, for a Super Admin preparing it (§1.3, P6)', function () {
        ['product' => $product, 'variants' => [$sixty]] = Px::ready();
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));
        $ae = (string) DB::table('platform.stores')->where('code', 'ae')->value('id');
        $super = catalogStoreScreensAs(Fx::staff(superAdmin: true));

        expect(catalogStoreScreensToast($super->post("/admin/products/{$product}/stores/ae/choose", ['active' => true])))->toBe('Switched on in the store')
            ->and(DB::table('catalog.store_variants')->where('store_id', $ae)->where('variant_id', $sixty)->value('is_active'))->toBeTrue();

        $uploaded = catalogStoreScreensUpload($super, [['code' => catalogStoreScreensCode($sixty), 'price' => 1]], 'ae');

        expect(catalogStoreScreensToast($uploaded))->toBe('File uploaded')
            ->and(DB::table('catalog.imports')->where('store_id', $ae)->count())->toBe(1);
    });

    it('keeps a label deactivated since it was attached: shown marked, and kept when the labels are saved', function () {
        ['product' => $product] = Px::ready();
        catalogStoreScreensOn('eg', $product);
        $summer = catalogStoreScreensLabel('Summer');
        $sale = catalogStoreScreensLabel('Sale');
        $eg = Fx::storeId('eg');
        Fx::asSystem(function () use ($eg, $product, $summer): void {
            app(AttachLabelsHandler::class)->handle(new AttachLabels($eg, $product, [$summer]));
            app(DeactivateLabelHandler::class)->handle(new DeactivateLabel($summer));
        });
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_LABELS], ['eg']);

        $browser->get("/admin/products/{$product}?tab=stores")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('labels', fn (Collection $labels): bool => $labels->contains(fn (array $label): bool => $label['id'] === $summer && $label['active'] === false)));

        expect(catalogStoreScreensToast($browser->post("/admin/products/{$product}/stores/eg/labels", ['label_ids' => [$summer, $sale]])))->toBe('Labels saved')
            ->and(DB::table('catalog.store_product_labels')->where('store_id', $eg)->where('product_id', $product)->pluck('label_id')->all())->toEqualCanonicalizing([$summer, $sale]);
    });

    it('opens a file of any size in its own recorded number of queries, and its list too', function () {
        ['variants' => [$ready]] = Px::ready();
        ['product' => $archived, 'variants' => [$archivedVariant]] = Px::ready();
        $draft = Px::product();
        Px::variant($draft, '7700');
        Fx::asSystem(function () use ($archived, $draft): void {
            app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archived));
            app(SetProductGalleryHandler::class)->handle(new SetProductGallery($draft, [Cx::media()]));
        });
        // A ready product's code, a draft's with a photo, an archived product's, and unknown ones.
        $kinds = [['code' => catalogStoreScreensCode($ready), 'price' => 1], ['code' => '7700', 'price' => 2], ['code' => catalogStoreScreensCode($archivedVariant), 'price' => 3]];
        $unknown = static fn (int $count): array => array_map(static fn (int $n): array => ['code' => (string) (770000 + $n), 'price' => 1], range(1, $count));
        $admin = catalogStoreScreensFiller();
        $few = basename((string) catalogStoreScreensUpload($admin, [...$kinds, ...$unknown(1)])->headers->get('Location'));
        $many = basename((string) catalogStoreScreensUpload($admin, [...$kinds, ...$unknown(57)])->headers->get('Location'));
        $own = catalogStoreScreensOwnQueries($admin, "/admin/store-files/{$few}");

        // 8 of its own and the list 4, however many items: none is read one item at a time (P13).
        expect($own)->toBe(8)
            ->and(catalogStoreScreensOwnQueries($admin, "/admin/store-files/{$many}"))->toBe($own)
            ->and(catalogStoreScreensOwnQueries($admin, '/admin/store-files'))->toBe(4);
    });
});

describe('the query budget (frontend.md §5, P21)', function () {
    it('opens a product\'s Stores tab in its own recorded number of queries, warm, however many stores and rows', function () {
        ['product' => $product, 'width' => $width] = Px::ready(['60 cm', '80 cm']);
        catalogStoreScreensOn('eg', $product);
        $browser = catalogStoreScreens([P::PRODUCT_VIEW, P::LISTING_CHOOSE, P::LISTING_SELLING, P::LISTING_UNAVAILABLE, P::LISTING_LABELS]);
        $uri = "/admin/products/{$product}?tab=stores";
        $own = catalogStoreScreensOwnQueries($browser, $uri);

        // 8 since #108 read the reader's grants once a request: the tab's own reads, within the 15.
        expect($own)->toBe(8)->and($own)->toBeLessThanOrEqual(15);

        // More of everything the tab shows: never one query a store, a variant or a label.
        foreach (range(1, 5) as $n) {
            Px::variant($product, (string) (8000 + $n), [$width => Px::value($width, "{$n}5 cm")]);
            catalogStoreScreensLabel("L{$n}");
        }

        catalogStoreScreensOn('sa', $product);
        catalogStoreScreensOn('ae', $product);

        expect(catalogStoreScreensOwnQueries($browser, $uri))->toBe($own);
    });
});
