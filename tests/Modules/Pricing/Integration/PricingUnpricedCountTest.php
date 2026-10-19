<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Pricing\Application\Command\CountUnpricedSizes\CountUnpricedSizes;
use Modules\Pricing\Application\Command\CountUnpricedSizes\CountUnpricedSizesHandler;
use Modules\Pricing\Application\Prices\NeedsAPriceCount;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPrice;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPriceHandler;
use Modules\Pricing\Application\Query\PriceViews\SizeView;
use Modules\Pricing\Domain\Repository\UnpricedCounts;
use Modules\Pricing\Infrastructure\Queue\CountUnpricedSizesJob;
use Modules\Pricing\Infrastructure\Queue\QueuedUnpricedRecounts;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Needs a Price (pricing.md §1.9, §4.3.3 P5): the list 50 at a time with its total and its search (Q14),
| none archived; its count kept per store (owner Q43, 2026-10-19, as Low Stock's) - counted again by a job
| after every change that can move it, with the list's own reader so they agree, and read for the menu in
| one query; a store never counted is counted live once.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Three sizes switched on in KSA, none priced: all three need a price.
 *
 * @return array{product: string, variants: list<string>, width: string}
 */
function unpricedSizes(): array
{
    $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));

    return $ready;
}

function unpricedKept(string $store = 'sa'): ?int
{
    $count = DB::table('pricing.unpriced_counts')->where('store_id', Rx::storeId($store))->value('unpriced');

    return $count === null ? null : (int) $count;
}

/**
 * @return list<string>
 */
function unpricedListed(?string $search = null): array
{
    return array_map(static fn (SizeView $size): string => $size->variantId, Fx::asSystem(fn () => app(NeedsAPriceHandler::class)->handle(new NeedsAPrice(Rx::storeId('sa'), $search)))->rows);
}

it('keeps the count in step with the list after each change - priced, removed, switched off and on, archived', function () {
    $ready = unpricedSizes();
    [$a, $b, $c] = $ready['variants'];

    expect(unpricedKept())->toBe(3);

    Rx::set('sa', [$a => '100']);

    expect(unpricedKept())->toBe(2)->toBe(count(unpricedListed()));

    Rx::remove('sa', [$a]);

    expect(unpricedKept())->toBe(3);

    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], false, [$b])));

    expect(unpricedKept())->toBe(2);

    // Archived: it can't be priced until restored, so it leaves the list (and the count).
    Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($c)));

    expect(unpricedKept())->toBe(1)
        ->and(unpricedListed())->toBe([$a]);
});

it('lists 50 at a time with the total, found by name or code, and asks Catalog for its order', function () {
    $ready = Px::ready(array_map('strval', range(1, 52)));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));
    Rx::set('sa', [$ready['variants'][0] => '10']);
    $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][1])->value('code');
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    $page = app(NeedsAPriceHandler::class)->handle(new NeedsAPrice(Rx::storeId('sa')));
    $next = app(NeedsAPriceHandler::class)->handle(new NeedsAPrice(Rx::storeId('sa'), null, $page->next));

    expect([count($page->rows), $page->total, count($next->rows), $next->next])->toBe([50, 51, 1, null])
        ->and(unpricedListed($code))->toBe([$ready['variants'][1]]);

    Cx::actAsStaffWith([PricingPermissions::WHOLESALE_EDIT], ['sa']);

    expect(fn () => app(NeedsAPriceHandler::class)->handle(new NeedsAPrice(Rx::storeId('sa'))))->toThrow(Unauthorized::class);
});

it('reads the kept counts for the menu in one query, Catalog never read', function () {
    unpricedSizes();
    Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);
    app(NeedsAPriceCount::class)->count();
    $queries = Cx::recordQueries();

    expect(app(NeedsAPriceCount::class)->count())->toBe(3);

    $ours = array_values(array_filter((array) $queries, static fn (array $query): bool => str_contains($query['sql'], '"pricing".') || str_contains($query['sql'], '"catalog".')));

    expect(count($ours))->toBe(1)
        ->and($ours[0]['sql'])->toContain('"pricing"."unpriced_counts"');
});

it('counts a store never counted live, this once, and queues its count - keeping no number itself', function () {
    unpricedSizes();
    DB::table('pricing.unpriced_counts')->delete();
    Queue::fake();
    Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);

    expect(app(NeedsAPriceCount::class)->count())->toBe(3)
        ->and(unpricedKept())->toBeNull();
    Queue::assertPushed(CountUnpricedSizesJob::class, fn (CountUnpricedSizesJob $job): bool => $job->storeId === Rx::storeId('sa') && $job->delay === QueuedUnpricedRecounts::WAIT_SECONDS);
});

it('queues one count a store however many changes come before it runs', function () {
    [$a, $b] = unpricedSizes()['variants'];
    Queue::fake();

    Rx::set('sa', [$a => '100']);
    Rx::set('sa', [$b => '100']);

    Queue::assertPushed(CountUnpricedSizesJob::class, 1);
});

it('counts every store each night at 03:40 Riyadh time on one server, is the system\'s alone, and tries three times', function () {
    $events = collect(app(Schedule::class)->events())->filter(static fn ($event): bool => $event->description === CountUnpricedSizesJob::class)->values();

    $night = $events->first();
    assert($night instanceof Event);

    expect($events)->toHaveCount(1)
        ->and([$night->expression, $night->timezone, $night->onOneServer])->toBe(['40 3 * * *', 'Asia/Riyadh', true])
        ->and((new CountUnpricedSizesJob)->tries)->toBe(3);

    Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);

    expect(fn () => app(CountUnpricedSizesHandler::class)->handle(new CountUnpricedSizes))->toThrow(Unauthorized::class);

    unpricedSizes();
    DB::table('pricing.unpriced_counts')->delete();

    expect(Fx::asSystem(fn (): int => app(CountUnpricedSizesHandler::class)->handle(new CountUnpricedSizes)))->toBeGreaterThan(1)
        ->and(unpricedKept())->toBe(3)
        ->and(unpricedKept('ae'))->toBe(0);
});

it('never writes an older count over a newer one', function () {
    unpricedSizes();
    $store = StoreId::fromString(Rx::storeId('sa'));

    app(UnpricedCounts::class)->save($store, 7, CarbonImmutable::now()->addMinute()->toDateTimeImmutable());
    app(UnpricedCounts::class)->save($store, 2, CarbonImmutable::now()->toDateTimeImmutable());

    expect(unpricedKept())->toBe(7);
});

it('keeps its count only as a count can be: never below 0, a number only with when it was counted', function () {
    $row = ['store_id' => Rx::storeId('sa'), 'unpriced' => 1, 'counted_at' => now()];
    $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('pricing.unpriced_counts')->insert([...$row, ...$changed]));

    expect($insert(['unpriced' => -1]))->toThrow(QueryException::class, 'unpriced_counts_unpriced')
        ->and($insert(['counted_at' => null]))->toThrow(QueryException::class, 'unpriced_counts_counted')
        ->and($insert(['unpriced' => null]))->toThrow(QueryException::class, 'unpriced_counts_counted');
});
