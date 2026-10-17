<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Modules\Sync\Infrastructure\Queue\ReadStoreJob;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| Reading a store (sync.md §1.3-§1.7): the provider's prices and stock written through Pricing and
| Inventory; every code that cannot be written reported, and what is right again closed; one read per
| store at a time; a failed attempt tried again, the last one failing the store and reporting it; a
| store unwired meanwhile left alone; one audit entry per read.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-17 10:00:00+00');
    // Jobs are run here by hand, one at a time.
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The pretend provider bound in Odoo's place for this test. */
function syncReadsFake(): FakeProvider
{
    return FakeProvider::bound();
}

function syncReadsStore(): StoreId
{
    return StoreId::fromString(Fx::storeId('sa'));
}

/**
 * A ready product's sizes, switched on in KSA - the store sells them.
 *
 * @param  list<string>  $sizes
 * @return list<string>
 */
function syncReadsSelling(array $sizes = ['60 cm']): array
{
    $ready = Px::ready($sizes);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));

    return $ready['variants'];
}

function syncReadsCode(string $variantId): string
{
    return (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
}

/** The store's oldest waiting read, run as its job runs it. */
function syncReadsRun(): string
{
    $id = (string) DB::table('sync.reads')->where('state', 'QUEUED')->orderBy('queued_at')->orderBy('id')->value('id');
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($id)));

    return $id;
}

/**
 * @param  list<string>|null  $codes
 */
function syncReadsQueue(ReadKind $kind, ?array $codes = null): void
{
    Fx::asSystem(fn () => app(Reads::class)->queue(syncReadsStore(), $kind, $codes));
}

/**
 * @return list<string> the store's open entries, each as "KIND code-or-size"
 */
function syncReadsOpen(): array
{
    $open = array_map(
        static fn (stdClass $entry): string => $entry->kind.' '.($entry->code ?? $entry->variant_id ?? '-'),
        DB::table('sync.report_entries')->whereNull('closed_at')->orderBy('kind')->orderBy('id')->get()->all(),
    );
    sort($open);

    return $open;
}

describe('a read', function () {
    it('writes the provider\'s prices and stock, finishes with its counts, marks the connection read and audits once', function () {
        [$a, $b] = syncReadsSelling(['60 cm', '80 cm']);
        syncReadsFake()->values = [new ProviderValue(syncReadsCode($a), '120.50', 7), new ProviderValue(' '.syncReadsCode($b).' ', 80, 5.0)];

        $read = syncReadsRun();

        $row = DB::table('sync.reads')->where('id', $read)->first();

        expect(Rx::retail('sa', $a)?->amount_minor)->toBe(12050)
            ->and(Rx::retail('sa', $a)?->source)->toBe('PROVIDER')
            ->and(Rx::retail('sa', $b)?->amount_minor)->toBe(8000)
            ->and(DB::table('inventory.stock')->where('variant_id', $a)->value('in_stock'))->toBe(7)
            ->and(DB::table('inventory.stock')->where('variant_id', $b)->value('in_stock'))->toBe(5)
            ->and([$row?->state, $row?->prices_changed, $row?->stock_changed, $row?->skipped])->toBe(['DONE', 2, 2, 0])
            ->and(DB::table('sync.connections')->value('last_read_at'))->not->toBeNull()
            ->and(DB::table('sync.connections')->value('last_full_read_at'))->not->toBeNull()
            ->and(Fx::audits('sync.read.done', $read))->toBe(1)
            ->and(syncReadsOpen())->toBe([]);
    });

    it('asks the provider for what changed since the last read, the hour\'s; and only the codes, a notice\'s', function () {
        syncReadsRun();
        $last = CarbonImmutable::parse((string) DB::table('sync.connections')->value('last_read_at'));

        syncReadsQueue(ReadKind::Hourly);
        syncReadsRun();
        syncReadsQueue(ReadKind::Notified, ['123', '456']);
        syncReadsRun();

        expect(syncReadsFake()->reads[0])->toBe(['codes' => null, 'changedSince' => null])
            ->and(syncReadsFake()->reads[1]['changedSince']?->getTimestamp())->toBe($last->getTimestamp())
            ->and(syncReadsFake()->reads[2])->toBe(['codes' => ['123', '456'], 'changedSince' => null])
            ->and(DB::table('sync.connections')->value('last_full_read_at'))->toBe(DB::table('sync.reads')->where('kind', 'NOW')->value('finished_at'));
    });
});

describe('what a read reports (§1.6)', function () {
    it('reports a code we do not have, never creating it, and closes it once a size carries it', function () {
        syncReadsFake()->values = [new ProviderValue('987654', '10', 1)];

        syncReadsRun();

        expect(syncReadsOpen())->toBe(['UNKNOWN_CODE 987654'])
            ->and(DB::table('catalog.variants')->where('code', '987654')->exists())->toBeFalse();

        $made = Px::variant(Px::product('Hinge'), '987654');
        syncReadsQueue(ReadKind::Now);
        syncReadsRun();

        // A draft product's size is priced too, and listed for staff (§1.4 #4).
        expect(syncReadsOpen())->toBe(["OFF_OR_DRAFT_PRICED {$made}"])
            ->and(Rx::retail('sa', $made)?->amount_minor)->toBe(1000);
    });

    it('skips and reports an archived size, and a value that is not one - a price of 0, a fraction of a piece - keeping a number below 0 as 0', function () {
        [$archived, $zero, $fraction, $below] = syncReadsSelling(['1', '2', '3', '4']);
        DB::table('catalog.variants')->where('id', $archived)->update(['is_archived' => true]);
        syncReadsFake()->values = [
            new ProviderValue(syncReadsCode($archived), '10', 1),
            new ProviderValue(syncReadsCode($zero), '0', 1),
            new ProviderValue(syncReadsCode($fraction), '10', 1.5),
            new ProviderValue(syncReadsCode($below), '10', -3),
        ];

        $read = syncReadsRun();

        expect(syncReadsOpen())->toEqualCanonicalizing(["ARCHIVED_SKIPPED {$archived}", "BAD_VALUE {$zero}", "BAD_VALUE {$fraction}", "BAD_VALUE {$below}"])
            ->and(Rx::retail('sa', $zero))->toBeNull()
            ->and(DB::table('inventory.stock')->where('variant_id', $fraction)->exists())->toBeFalse()
            ->and(DB::table('inventory.stock')->where('variant_id', $below)->value('in_stock'))->toBeNull()
            ->and(DB::table('sync.reads')->where('id', $read)->value('skipped'))->toBe(4);

        // Good values the next time: their entries close; the archived size waits to be restored.
        syncReadsFake()->values = [new ProviderValue(syncReadsCode($zero), '10', 1), new ProviderValue(syncReadsCode($fraction), '10', 2), new ProviderValue(syncReadsCode($below), '10', 3)];
        syncReadsQueue(ReadKind::Now);
        syncReadsRun();

        expect(syncReadsOpen())->toBe(["ARCHIVED_SKIPPED {$archived}"]);
    });

    it('lists an off or draft size it priced, a sale it stopped and a discount it made skip a size', function () {
        $category = Px::category('Kitchens');
        $ready = Px::ready(['60 cm', '80 cm', '100 cm'], $category);
        [$onSale, $discounted, $off] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
        DB::table('catalog.store_variants')->where('store_id', Fx::storeId('sa'))->where('variant_id', $off)->delete();
        DB::table('pricing.retail_prices')->insert(array_map(static fn (string $size): array => ['store_id' => Fx::storeId('sa'), 'variant_id' => $size, 'amount_minor' => 20000, 'currency_code' => 'SAR', 'source' => 'PROVIDER', 'updated_at' => now()], [$onSale, $discounted]));
        $sale = Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$onSale], null, '160', null, null)));
        $discount = Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Fx::storeId('sa'), $category, null, '50', 'BOTH', null, null)));
        CarbonImmutable::setTestNow('2026-10-17 11:00:00+00');
        // 160 on 400 is 60% off; 50 off 120 is 42%: both past 30%.
        syncReadsFake()->values = [new ProviderValue(syncReadsCode($onSale), '400', null), new ProviderValue(syncReadsCode($discounted), '120', null), new ProviderValue(syncReadsCode($off), '90', null)];

        syncReadsRun();

        expect(syncReadsOpen())->toContain("SALE_STOPPED {$onSale}", "DISCOUNT_SKIPPED {$discounted}", "OFF_OR_DRAFT_PRICED {$off}")
            ->and(DB::table('sync.report_entries')->where('kind', 'SALE_STOPPED')->value('sale_id'))->toBe($sale)
            ->and((string) DB::table('sync.report_entries')->where('kind', 'DISCOUNT_SKIPPED')->value('detail'))->toContain($discount);

        // The price back where the discount reaches it: its entry closes.
        syncReadsFake()->values = [new ProviderValue(syncReadsCode($discounted), '200', null)];
        syncReadsQueue(ReadKind::Now);
        syncReadsRun();

        expect(syncReadsOpen())->not->toContain("DISCOUNT_SKIPPED {$discounted}");
    });

    it('finds, at night only, our sizes the provider does not know, and closes them once it sends them', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sent, $missing] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
        syncReadsFake()->values = [new ProviderValue(syncReadsCode($sent), '10', 1)];

        syncReadsRun();
        expect(syncReadsOpen())->toBe([]);

        syncReadsQueue(ReadKind::Nightly);
        syncReadsRun();
        expect(syncReadsOpen())->toBe(["NOT_IN_PROVIDER {$missing}"]);

        syncReadsFake()->values[] = new ProviderValue(syncReadsCode($missing), '10', 1);
        syncReadsQueue(ReadKind::Now);
        syncReadsRun();
        expect(syncReadsOpen())->toBe([]);
    });
});

describe('one read at a time, and failures (§1.3, §1.7)', function () {
    it('lets a read wait while another of the store runs, and starts it when that one ends', function () {
        $first = (string) DB::table('sync.reads')->value('id');
        DB::table('sync.reads')->where('id', $first)->update(['state' => 'RUNNING', 'started_at' => now()]);
        syncReadsQueue(ReadKind::Nightly);
        $second = (string) DB::table('sync.reads')->where('kind', 'NIGHTLY')->value('id');

        Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($second)));

        expect(DB::table('sync.reads')->where('id', $second)->value('state'))->toBe('QUEUED')
            ->and(syncReadsFake()->reads)->toBe([]);

        DB::table('sync.reads')->where('id', $first)->update(['state' => 'QUEUED', 'started_at' => null]);
        Queue::fake();
        Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($first)));

        Queue::assertPushed(ReadStoreJob::class, fn (ReadStoreJob $job): bool => $job->readId === $second);
    });

    it('queues one more full read of a kind, never two', function () {
        syncReadsQueue(ReadKind::Nightly);
        syncReadsQueue(ReadKind::Nightly);
        syncReadsQueue(ReadKind::Now);

        expect(DB::table('sync.reads')->where('state', 'QUEUED')->pluck('kind')->sort()->values()->all())->toBe(['NIGHTLY', 'NOW']);
    });

    it('takes a read running for over 30 minutes as dead, so the store is not stuck', function () {
        DB::table('sync.reads')->update(['state' => 'RUNNING', 'started_at' => now()->subMinutes(31)]);
        syncReadsQueue(ReadKind::Nightly);

        syncReadsRun();

        expect(DB::table('sync.reads')->where('kind', 'NOW')->value('state'))->toBe('FAILED')
            ->and(DB::table('sync.reads')->where('kind', 'NIGHTLY')->value('state'))->toBe('DONE');
    });

    it('puts a failed attempt back to be tried again; after the last, fails the read, the store failing and reported; a later read mends both', function () {
        syncReadsFake()->failure = 'Timed out';
        $read = (string) DB::table('sync.reads')->value('id');

        expect(fn () => syncReadsRun())->toThrow(ConnectionFailed::class, 'Timed out')
            ->and(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('QUEUED');

        Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, 'Timed out'));

        expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('FAILED')
            ->and(DB::table('sync.connections')->value('state'))->toBe('FAILING')
            ->and(DB::table('sync.connections')->value('last_error'))->toBe('Timed out')
            ->and(syncReadsOpen())->toBe(['READ_FAILED -'])
            ->and(Fx::audits('sync.read.failed', $read))->toBe(1);

        syncReadsFake()->failure = null;
        syncReadsQueue(ReadKind::Now);
        syncReadsRun();

        expect(DB::table('sync.connections')->value('state'))->toBe('OK')
            ->and(DB::table('sync.connections')->value('last_error'))->toBeNull()
            ->and(syncReadsOpen())->toBe([]);
    });

    it('fails a read of a store unwired meanwhile, reading nothing', function () {
        DB::table('sync.connections')->delete();

        $read = syncReadsRun();

        expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('FAILED')
            ->and(DB::table('sync.reads')->where('id', $read)->value('error'))->toBe('The store was unwired.')
            ->and(syncReadsFake()->reads)->toBe([]);
    });

    it('is the system\'s, and a Super Admin\'s - never a staff member\'s', function () {
        $read = (string) DB::table('sync.reads')->value('id');
        Fx::actAsStaff(Fx::staff());

        expect(fn () => app(RunReadHandler::class)->handle(new RunRead($read)))->toThrow(Unauthorized::class);
    });
});
