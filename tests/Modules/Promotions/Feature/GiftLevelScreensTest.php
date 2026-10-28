<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Inventory\Public\InventoryPermissions;
use Modules\Promotions\Application\PromotionsPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Promotions' gift levels screen over HTTP (promotions.md §4.1 M4): the store's levels highest first,
| the size search for the gift, New Level…, Edit… and the switch - what the page is given, who is
| refused, and where each refusal lands.
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
function giftScreens(array $permissions = [PromotionsPermissions::GIFT_MANAGE]): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, ['sa']);
    $browser = new AdminBrowser('10.9.4.'.random_int(20, 250));

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
function giftScreensProps(AdminBrowser $browser, string $uri): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $page->component('Promotions/Admin/GiftLevels/Index');
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @param  TestResponse<Response>  $response
 */
function giftScreensFieldError(TestResponse $response, string $field): ?string
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first($field) === '' ? null : $errors->first($field);
    }

    $message = is_array($errors) ? ($errors['default']['messages'][$field][0] ?? null) : null;

    return is_string($message) ? $message : null;
}

/**
 * The dialog as it posts.
 *
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function giftScreensForm(string $variantId, array $with = []): array
{
    return ['store' => 'sa', 'amount' => '500', 'gift_variant' => $variantId, 'for_public' => true, 'for_company' => true, 'starts_at' => null, 'ends_at' => null, ...$with];
}

function giftScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Promotions/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

it('lists the levels highest first, each with its gift named and its state', function () {
    [$small, $big] = Px::ready(['60 cm', '80 cm'])['variants'];
    Pr::giftLevel($small, 200_00);
    Pr::giftLevel($big, 900_00);
    Pr::giftLevel($small, 500_00, with: ['active' => false]);

    $props = giftScreensProps(giftScreens([PromotionsPermissions::VIEW]), '/admin/gift-levels');

    expect(array_column($props['levels'], 'amountText'))->toBe(['900.00', '500.00', '200.00'])
        ->and(array_column($props['levels'], 'state'))->toBe(['GIVING', 'OFF', 'GIVING'])
        ->and([$props['levels'][0]['productName'] !== null, $props['levels'][0]['variantCode'] !== null, $props['mayManage'], $props['sizes']])->toBe([true, true, false, []]);
});

it('finds sizes of ready products for a manager who searches, never for a reader', function () {
    $ready = Px::ready(['60 cm']);
    $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');

    $manager = giftScreensProps(giftScreens(), '/admin/gift-levels?search='.$code);
    $reader = giftScreensProps(giftScreens([PromotionsPermissions::VIEW]), '/admin/gift-levels?search='.$code);

    expect([array_column($manager['sizes'], 'id'), $manager['sizes'][0]['code'] ?? null, $reader['sizes']])->toBe([[$ready['variants'][0]], $code, []]);
});

it('creates a level from the dialog, edits it and switches it, saying each', function () {
    [$gift] = Px::ready(['60 cm'])['variants'];
    $browser = giftScreens();

    $created = $browser->post('/admin/gift-levels', giftScreensForm($gift, ['amount' => '٤٥٠.٥٠']));
    $level = (string) DB::table('promotions.gift_levels')->value('id');
    $saved = $browser->post("/admin/gift-levels/{$level}", giftScreensForm($gift, ['amount' => '600', 'for_company' => false]));
    $off = $browser->post("/admin/gift-levels/{$level}/switch", ['active' => false]);
    $row = DB::table('promotions.gift_levels')->where('id', $level)->first();
    assert($row instanceof stdClass);

    expect([AdminBrowser::flashed($created, 'status'), AdminBrowser::flashed($saved, 'status'), AdminBrowser::flashed($off, 'status')])->toBe(['Gift level created.', 'Gift level saved.', 'Gift level turned off.'])
        ->and([$row->amount_minor, $row->for_company, $row->active])->toBe([600_00, false, false]);
});

it('says a clash with another level in the dialog, and an amount it cannot read beside its box', function () {
    [$gift] = Px::ready(['60 cm'])['variants'];
    Pr::giftLevel($gift, 500_00);
    $browser = giftScreens();

    $clash = $browser->post('/admin/gift-levels', giftScreensForm($gift));
    $bad = $browser->post('/admin/gift-levels', giftScreensForm($gift, ['amount' => '1.234']));

    expect(giftScreensFieldError($clash, 'clash'))->not->toBeNull()
        ->and(giftScreensFieldError($bad, 'amount'))->toBe('Enter an amount with at most 2 decimals.')
        ->and(DB::table('promotions.gift_levels')->count())->toBe(1);
});

it('refuses a change by a reader who may only view', function () {
    [$gift] = Px::ready(['60 cm'])['variants'];

    $refused = giftScreens([PromotionsPermissions::VIEW])->post('/admin/gift-levels', giftScreensForm($gift));

    expect([$refused->getStatusCode(), DB::table('promotions.gift_levels')->count()])->toBe([302, 0]);
});

it('reads the page in a fixed few queries, the same for one level as for six', function () {
    [$gift, $other] = Px::ready(['60 cm', '80 cm'])['variants'];
    Pr::giftLevel($gift, 100_00);
    $count = static fn (): int => giftScreensOwnQueries(giftScreens(), '/admin/gift-levels');
    $one = $count();

    foreach (range(2, 6) as $n) {
        Pr::giftLevel($n % 2 === 0 ? $other : $gift, $n * 100_00);
    }

    // 12: the 9 of the page, and the tick box's facts from Inventory (each size's stock, the store's
    // wiring), 2026-10-20 — the same however many levels.
    expect($count())->toBe($one)
        ->and($one)->toBe(12);
});

describe('the stock tick box (owner\'s Q11 (c))', function () {
    it('gives the dialog the box\'s facts: the store wired, a size not yet limited to its stock, and who may tick', function () {
        Ix::wired();
        $ready = Px::ready(['60 cm']);
        // Every size counts on stock by default (inventory.md §9.2 #24): the box is for one switched off.
        Ix::switchOff('sa', [$ready['variants'][0]]);
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        Pr::giftLevel($ready['variants'][0], 300_00);

        $stock = giftScreensProps(giftScreens([PromotionsPermissions::GIFT_MANAGE, InventoryPermissions::STOCK_MANAGE]), '/admin/gift-levels?search='.$code);
        $gifts = giftScreensProps(giftScreens(), '/admin/gift-levels?search='.$code);

        expect([$stock['sizes'][0]['wired'], $stock['sizes'][0]['stockDependent'], $stock['levels'][0]['wired'], $stock['levels'][0]['stockDependent']])->toBe([true, false, true, false])
            ->and([$stock['mayManageStock'], $gifts['mayManageStock']])->toBe([true, false]);
    });

    it('limits the size to its stock in the level\'s own save, ticked by someone who manages stock', function () {
        Ix::wired();
        [$gift] = Px::ready(['60 cm'])['variants'];
        Ix::switchOff('sa', [$gift]);

        $saved = giftScreens([PromotionsPermissions::GIFT_MANAGE, InventoryPermissions::STOCK_MANAGE])->post('/admin/gift-levels', giftScreensForm($gift, ['make_stock_dependent' => true]));

        expect(AdminBrowser::flashed($saved, 'status'))->toBe('Gift level created.')
            ->and(DB::table('inventory.stock')->where('variant_id', $gift)->value('stock_dependent'))->toBeTrue();
    });

    it('limits the size on an edit too, and refuses that edit to someone who may not manage stock', function () {
        Ix::wired();
        [$gift] = Px::ready(['60 cm'])['variants'];
        Ix::switchOff('sa', [$gift]);
        $level = Pr::giftLevel($gift, 300_00);

        $refused = giftScreens()->post("/admin/gift-levels/{$level}", giftScreensForm($gift, ['amount' => '350', 'make_stock_dependent' => true]));

        expect([$refused->getStatusCode(), DB::table('promotions.gift_levels')->where('id', $level)->value('amount_minor')])->toBe([302, 300_00])
            ->and(DB::table('inventory.stock')->where('variant_id', $gift)->value('stock_dependent'))->not->toBeTrue();

        $saved = giftScreens([PromotionsPermissions::GIFT_MANAGE, InventoryPermissions::STOCK_MANAGE])->post("/admin/gift-levels/{$level}", giftScreensForm($gift, ['amount' => '350', 'make_stock_dependent' => true]));

        expect([AdminBrowser::flashed($saved, 'status'), DB::table('promotions.gift_levels')->where('id', $level)->value('amount_minor')])->toBe(['Gift level saved.', 350_00])
            ->and(DB::table('inventory.stock')->where('variant_id', $gift)->value('stock_dependent'))->toBeTrue();
    });

    it('refuses the tick from someone who may not manage stock, saving nothing', function () {
        Ix::wired();
        [$gift] = Px::ready(['60 cm'])['variants'];
        Ix::switchOff('sa', [$gift]);

        $refused = giftScreens()->post('/admin/gift-levels', giftScreensForm($gift, ['make_stock_dependent' => true]));

        expect([$refused->getStatusCode(), DB::table('promotions.gift_levels')->count()])->toBe([302, 0])
            ->and(DB::table('inventory.stock')->where('variant_id', $gift)->value('stock_dependent'))->not->toBeTrue();
    });
});
