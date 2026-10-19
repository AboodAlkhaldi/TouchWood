<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindow;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindowHandler;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSale;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSaleHandler;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindows;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindowsHandler;
use Modules\Pricing\Application\Command\RemoveSale\RemoveSale;
use Modules\Pricing\Application\Command\RemoveSale\RemoveSaleHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapNote;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingSale;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsFor;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsForHandler;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Infrastructure\Queue\ApplyPriceWindowJob;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| The timed tasks and the note before saving (pricing.md §1.3, §1.10, §3; step 4b): a sale's start and
| end each queue a task that pushes its sizes' prices into Catalog then; a task whose sale changed or
| went does nothing; the daily check pushes what a lost task left; the note names the sizes a sale
| reaches and every other sale on them in the same dates.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-13 10:00:00+00');
    Queue::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $variantIds
 */
function windowsSale(array $variantIds, ?string $percent = null, ?string $price = null, ?string $startsAt = null, ?string $endsAt = null, ?string $productId = null): string
{
    Rx::switchOn('sa', $variantIds, $variantIds === [] ? $productId : null);

    return Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), $productId, $variantIds, $percent, $price, $startsAt, $endsAt, false)));
}

function windowsShown(string $variantId): ?int
{
    $value = DB::table('catalog.store_variant_facts')->where('store_id', Rx::storeId('sa'))->where('variant_id', $variantId)->value('price_minor');

    return $value === null ? null : (int) $value;
}

/**
 * @return list<ApplyPriceWindowJob>
 */
function windowsTasks(string $saleId): array
{
    $queue = Queue::getFacadeRoot();
    assert($queue instanceof QueueFake);

    return array_values($queue->pushed(ApplyPriceWindowJob::class, static fn (ApplyPriceWindowJob $job): bool => $job->saleId === $saleId)->all());
}

function windowsDelay(ApplyPriceWindowJob $job): string
{
    return $job->delay instanceof DateTimeInterface ? CarbonImmutable::instance($job->delay)->toIso8601String() : '';
}

/**
 * @param  list<string>  $variantIds
 */
function windowsNote(array $variantIds, string $from, ?string $to, ?string $saleId = null, ?string $productId = null): OverlapNote
{
    return Fx::asSystem(fn (): OverlapNote => app(OverlapsForHandler::class)->handle(new OverlapsFor(Rx::storeId('sa'), $productId, $variantIds, $from, $to, $saleId)));
}

function windowsRun(ApplyPriceWindowJob $task): bool
{
    return Fx::asSystem(fn (): bool => app(ApplyPriceWindowHandler::class)->handle(new ApplyPriceWindow($task->storeId, $task->saleId, $task->moment)));
}

describe('the timed tasks (§1.10)', function () {
    it('queues one task for a sale\'s start and one for its end, each delayed to its moment', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);

        $scheduled = windowsSale([$size], price: '80', startsAt: '2026-10-20 00:00:00+00', endsAt: '2026-10-25 00:00:00+00');
        $now = windowsSale([$size], percent: '5', endsAt: '2026-10-30 00:00:00+00');
        $tasks = windowsTasks($scheduled);

        expect($tasks)->toHaveCount(2)
            ->and(array_map(windowsDelay(...), $tasks))
            ->toBe(['2026-10-20T00:00:00+00:00', '2026-10-25T00:00:00+00:00'])
            // A sale running from now needs no task at its start: the change pushed it.
            ->and(windowsTasks($now))->toHaveCount(1)
            // The queue keeps whole seconds: a moment with a fraction is due at the next one, never before.
            ->and(array_map(windowsDelay(...), windowsTasks(windowsSale([$size], percent: '5', startsAt: '2026-10-20 10:00:00.5+00')))
            )->toBe(['2026-10-20T10:00:01+00:00']);
    });

    it('pushes the sale\'s price into Catalog at its start, and the retail price back at its end', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        [$start, $end] = windowsTasks(windowsSale([$size], price: '80', startsAt: '2026-10-20 00:00:00+00', endsAt: '2026-10-25 00:00:00+00'));

        expect(windowsShown($size))->toBe(10000);

        CarbonImmutable::setTestNow('2026-10-20 00:00:00+00');

        expect(windowsRun($start))->toBeTrue()
            ->and(windowsShown($size))->toBe(8000);

        CarbonImmutable::setTestNow('2026-10-25 00:00:00+00');

        expect(windowsRun($end))->toBeTrue()
            ->and(windowsShown($size))->toBe(10000);
    });

    it('does nothing for a moment the sale no longer has, or a sale removed', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        $sale = windowsSale([$size], price: '80', startsAt: '2026-10-20 00:00:00+00');
        [$start] = windowsTasks($sale);

        Fx::asSystem(fn () => app(ChangeSaleHandler::class)->handle(new ChangeSale($sale, null, '80', '2026-10-21 00:00:00+00', null)));
        CarbonImmutable::setTestNow('2026-10-20 00:00:00+00');

        expect(windowsRun($start))->toBeFalse()
            ->and(windowsShown($size))->toBe(10000)
            ->and(windowsTasks($sale))->toHaveCount(2);

        CarbonImmutable::setTestNow('2026-10-13 11:00:00+00');
        Fx::asSystem(fn () => app(RemoveSaleHandler::class)->handle(new RemoveSale($sale)));

        expect(windowsRun(windowsTasks($sale)[1]))->toBeFalse();
    });

    it('is the system\'s alone', function () {
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);

        expect(fn () => app(ApplyPriceWindowHandler::class)->handle(new ApplyPriceWindow(Rx::storeId('sa'), 'x', '2026-10-20 00:00:00+00')))->toThrow(Unauthorized::class)
            ->and(fn () => app(CheckPriceWindowsHandler::class)->handle(new CheckPriceWindows))->toThrow(Unauthorized::class);
    });
});

describe('the daily check (§1.10)', function () {
    it('pushes every size whose sale started or ended in the last day, in case a task was lost', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        $old = Px::ready()['variants'][0];
        Rx::set('sa', [$a => '100', $b => '100', $old => '100']);
        windowsSale([$a, $b], price: '80', startsAt: '2026-10-20 00:00:00+00');
        windowsSale([$old], price: '90', startsAt: '2026-10-18 00:00:00+00');

        CarbonImmutable::setTestNow('2026-10-20 03:30:00+00');

        // No task ran: Catalog still shows the retail prices.
        expect(windowsShown($a))->toBe(10000)
            ->and(Fx::asSystem(fn (): int => app(CheckPriceWindowsHandler::class)->handle(new CheckPriceWindows)))->toBe(2)
            ->and(windowsShown($a))->toBe(8000)
            ->and(windowsShown($b))->toBe(8000)
            // Started two days before: not the daily check's.
            ->and(windowsShown($old))->toBe(10000);
    });

    it('pushes every other store when one fails, and still fails the run', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        Rx::set('eg', [$size => '100']);
        windowsSale([$size], price: '80', startsAt: '2026-10-20 00:00:00+00');
        Rx::switchOn('eg', [$size]);
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('eg'), null, [$size], null, '80', '2026-10-20 00:00:00+00', null, false)));
        // The store the check reaches first fails, so the other is reached only if the check goes on.
        [$failing, $other] = strcmp(Rx::storeId('sa'), Rx::storeId('eg')) < 0 ? [Rx::storeId('sa'), Rx::storeId('eg')] : [Rx::storeId('eg'), Rx::storeId('sa')];
        $real = app(PriceCandidates::class);
        app()->instance(PriceCandidates::class, new readonly class($real, $failing) implements PriceCandidates
        {
            public function __construct(private PriceCandidates $real, private string $failing) {}

            public function replace(StoreId $store, array $candidates): void
            {
                $store->value === $this->failing ? throw new RuntimeException('down') : $this->real->replace($store, $candidates);
            }

            public function forVariants(StoreId $store, array $variantIds): array
            {
                return $this->real->forVariants($store, $variantIds);
            }

            public function variantsWithCandidates(StoreId $store): array
            {
                return $this->real->variantsWithCandidates($store);
            }

            public function inStores(array $stores, array $variantIds): array
            {
                return $this->real->inStores($stores, $variantIds);
            }

            public function reducedAt(StoreId $store, DateTimeImmutable $at): array
            {
                return $this->real->reducedAt($store, $at);
            }
        });
        CarbonImmutable::setTestNow('2026-10-20 03:30:00+00');

        expect(fn () => Fx::asSystem(fn (): int => app(CheckPriceWindowsHandler::class)->handle(new CheckPriceWindows)))->toThrow(RuntimeException::class, 'down')
            ->and((int) DB::table('catalog.store_variant_facts')->where('store_id', $other)->where('variant_id', $size)->value('price_minor'))->toBe(8000);
    });
});

describe('the note before saving (§1.3)', function () {
    it('names the sizes a sale reaches and every other sale on them in the same dates', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$a, $b] = $ready['variants'];
        Rx::set('sa', [$a => '100', $b => '100']);
        $first = windowsSale([$a], percent: '10', startsAt: '2026-10-20 00:00:00+00', endsAt: '2026-10-25 00:00:00+00');
        $second = windowsSale([$a], percent: '5', startsAt: '2026-10-26 00:00:00+00');
        $onB = windowsSale([$b], price: '90', startsAt: '2026-10-20 00:00:00+00');
        $note = windowsNote(...);
        $ids = static fn (OverlapNote $result): array => array_map(static fn (OverlappingSale $sale): string => $sale->id, $result->sales);

        $wide = $note([$a], '2026-10-24 00:00:00+00', '2026-10-27 00:00:00+00');
        $whole = $note([], '2026-10-21 00:00:00+00', '2026-10-22 00:00:00+00', null, $ready['product']);

        expect($wide->variantIds)->toBe([$a])
            ->and($ids($wide))->toEqualCanonicalizing([$first, $second])
            ->and($ids($note([$a], '2026-10-21 00:00:00+00', '2026-10-22 00:00:00+00')))->toBe([$first])
            ->and($ids($note([$a], '2026-10-21 00:00:00+00', '2026-10-22 00:00:00+00', $first)))->toBe([])
            // A sale's id as callers write a ULID, in capitals: still itself.
            ->and($ids($note([$a], '2026-10-21 00:00:00+00', '2026-10-22 00:00:00+00', strtoupper($first))))->toBe([])
            ->and($whole->variantIds)->toEqualCanonicalizing([$a, $b])
            ->and($ids($whole))->toEqualCanonicalizing([$first, $onB])
            ->and(collect($whole->sales)->firstWhere('id', $onB)?->sharedVariantIds)->toBe([$b])
            ->and(collect($whole->sales)->firstWhere('id', $onB)?->amountMinor)->toBe(9000);
    });

    it('is read by any of the three Pricing jobs in that store, and no one else', function () {
        $size = Px::ready()['variants'][0];
        Rx::switchOn('sa', [$size]);
        $read = fn () => app(OverlapsForHandler::class)->handle(new OverlapsFor(Rx::storeId('sa'), null, [$size], null, null));

        Cx::actAsStaffWith([PricingPermissions::WHOLESALE_EDIT], ['sa']);

        expect($read()->variantIds)->toBe([$size]);

        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['eg']);

        expect($read)->toThrow(Unauthorized::class)
            // A store that does not exist, to a reader allowed everywhere: invalid input, as saving.
            ->and(Rx::invalid(fn () => Fx::asSystem(fn () => app(OverlapsForHandler::class)->handle(new OverlapsFor(strtolower((string) Str::ulid()), null, [$size], null, null))))?->attribute)->toBe('store');
    });
});
