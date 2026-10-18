<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindow;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindowHandler;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindows;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindowsHandler;
use Modules\Pricing\Application\Command\EndCategoryDiscount\EndCategoryDiscount;
use Modules\Pricing\Application\Command\EndCategoryDiscount\EndCategoryDiscountHandler;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Infrastructure\Queue\ApplyPriceWindowJob;
use Modules\Pricing\Public\Events\DiscountReachChecked;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| A "discount skipped" entry closes the moment the discount reaches its size again (owner, 2026-10-18,
| sync.md §9.3 #28): the ceiling raised, the discount ended, the provider's price lowered - Pricing says
| which sizes it priced again and which a discount still leaves out; Sync closes the rest, by PRICING.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-18 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'the-secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * Two sizes of one product in a discounted category, the provider pricing them at 120: 50 off is 42%,
 * past the 30% ceiling, so the discount skips both; the read reports them.
 *
 * @return array{sizes: list<string>, discount: string}
 */
function syncReachSkipped(?string $endsAt = null): array
{
    $category = Px::category('Kitchens');
    $ready = Px::ready(['60 cm', '80 cm'], $category);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    $discount = Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Fx::storeId('sa'), $category, null, '50', 'BOTH', null, $endsAt)));
    $code = static fn (string $size): string => (string) DB::table('catalog.variants')->where('id', $size)->value('code');
    FakeProvider::bound()->values = array_map(static fn (string $size): ProviderValue => new ProviderValue($code($size), '120', null), $ready['variants']);
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    return ['sizes' => $ready['variants'], 'discount' => $discount];
}

/**
 * @param  list<string>  $sizes
 * @return list<string|null> who closed each size's entry, in the sizes' order
 */
function syncReachClosedBy(array $sizes): array
{
    return array_map(static fn (string $size): ?string => DB::table('sync.report_entries')->where('kind', 'DISCOUNT_SKIPPED')->where('variant_id', $size)->value('closed_by'), $sizes);
}

it('closes the entries at once when the ceiling is raised so the discount reaches the sizes', function () {
    ['sizes' => $sizes] = syncReachSkipped();

    expect(syncReachClosedBy($sizes))->toBe([null, null]);

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 50));

    expect(syncReachClosedBy($sizes))->toBe(['PRICING', 'PRICING']);
});

it('keeps them open while the discount still skips the sizes - a ceiling raised not far enough', function () {
    ['sizes' => $sizes] = syncReachSkipped();

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 40));

    expect(syncReachClosedBy($sizes))->toBe([null, null]);
});

it('closes them when the discount ends - the last one the store had', function () {
    ['sizes' => $sizes, 'discount' => $discount] = syncReachSkipped();
    CarbonImmutable::setTestNow('2026-10-18 11:00:00+00');

    Fx::asSystem(fn () => app(EndCategoryDiscountHandler::class)->handle(new EndCategoryDiscount($discount)));

    expect(syncReachClosedBy($sizes))->toBe(['PRICING', 'PRICING']);
});

it('closes them when the discount reaches its end time - its timed task', function () {
    ['sizes' => $sizes, 'discount' => $discount] = syncReachSkipped('2026-10-18 12:00:00+00');
    $queue = Queue::getFacadeRoot();
    assert($queue instanceof QueueFake);
    $task = $queue->pushed(ApplyPriceWindowJob::class, static fn (ApplyPriceWindowJob $job): bool => $job->saleId === $discount)->last();
    assert($task instanceof ApplyPriceWindowJob);
    CarbonImmutable::setTestNow('2026-10-18 12:00:00+00');

    Fx::asSystem(fn (): bool => app(ApplyPriceWindowHandler::class)->handle(new ApplyPriceWindow($task->storeId, $task->saleId, $task->moment)));

    expect(syncReachClosedBy($sizes))->toBe(['PRICING', 'PRICING']);
});

it('closes them at the daily check when the end\'s own task was lost', function () {
    ['sizes' => $sizes] = syncReachSkipped('2026-10-18 12:00:00+00');
    CarbonImmutable::setTestNow('2026-10-18 13:00:00+00');

    Fx::asSystem(fn () => app(CheckPriceWindowsHandler::class)->handle(new CheckPriceWindows));

    expect(syncReachClosedBy($sizes))->toBe(['PRICING', 'PRICING']);
});

it('keeps an entry open while another discount still skips the size', function () {
    ['sizes' => $sizes] = syncReachSkipped();
    $category = (string) DB::table('pricing.category_discounts')->value('category_id');
    // 60 off 120 is 50%; 50 off is 42%.
    Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Fx::storeId('sa'), $category, null, '60', 'BOTH', null, null)));

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 45));

    expect(syncReachClosedBy($sizes))->toBe([null, null]);
});

it('closes one when the provider lowers its price so the discount reaches it, the other left as it is', function () {
    ['sizes' => [$a, $b]] = syncReachSkipped();
    $code = (string) DB::table('catalog.variants')->where('id', $a)->value('code');
    FakeProvider::bound()->values = [new ProviderValue($code, '200', null)];
    Fx::asSystem(fn () => app(Reads::class)->queue(StoreId::fromString(Fx::storeId('sa')), ReadKind::Now));

    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead((string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id'))));

    expect(syncReachClosedBy([$a, $b]))->toBe(['PRICING', null]);
});

it('is told by Pricing only for a wired store, naming the sizes priced again and those still skipped', function () {
    ['sizes' => $sizes, 'discount' => $discount] = syncReachSkipped();
    Event::fake([DiscountReachChecked::class]);

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 40));
    // A store not wired: its discounts are Pricing's own business.
    $category = Px::category('Bathrooms');
    $other = Px::ready(['60 cm'], $category);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('ae'), $other['product'], true)));
    Rx::set('ae', [$other['variants'][0] => '120']);
    Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Fx::storeId('ae'), $category, null, '10', 'BOTH', null, null)));

    Event::assertDispatchedTimes(DiscountReachChecked::class, 1);
    Event::assertDispatched(DiscountReachChecked::class, fn (DiscountReachChecked $event): bool => $event->storeId === Fx::storeId('sa')
        && $event->variantIds === $sizes
        && $event->skipped === array_map(static fn (string $size): array => ['discountId' => $discount, 'variantId' => $size], $sizes));
});

it('never touches another store\'s entries', function () {
    ['sizes' => $sizes] = syncReachSkipped();
    DB::table('sync.report_entries')->where('kind', 'DISCOUNT_SKIPPED')->update(['store_id' => Fx::storeId('ae')]);
    // One of the changed store's own still open, so the look goes on to close.
    DB::table('sync.report_entries')->insert(['store_id' => Fx::storeId('sa'), 'kind' => 'DISCOUNT_SKIPPED', 'variant_id' => '01jbsizesizesizesizesizesi', 'detail' => '{}', 'opened_at' => now(), 'seen_at' => now()]);

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 50));

    expect(syncReachClosedBy($sizes))->toBe([null, null]);
});
