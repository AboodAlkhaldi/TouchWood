<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariant;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariantHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Command\RebuildPriceCandidates\RebuildPriceCandidates;
use Modules\Pricing\Application\Command\RebuildPriceCandidates\RebuildPriceCandidatesHandler;
use Modules\Pricing\Application\Command\RemoveRetailPrice\RemoveRetailPrice;
use Modules\Pricing\Application\Command\RemoveRetailPrice\RemoveRetailPriceHandler;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPrice;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPriceHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\Exception\ProviderOwnsPrice;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Presentation\Console\RebuildPriceCandidatesCommand;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

/*
| Retail prices (pricing.md §1.1, §1.2, §1.6 "materialized", §1.10, §3; §8 #5, #7, #8, #14): set and
| removed per store under Edit Prices and Sales there; refused - never rounded - with too many
| decimals or at 0 or less; an archived size read-only until restored; an off store's only for
| whoever may switch stores; a wired store's never; each change audited from and to, its candidates
| written again and Catalog told, inside one transaction under the store's lock. The repair writes
| the same candidates from nothing.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Set prices as an actor already acting.
 *
 * @param  array<string, string>  $prices
 */
function pricingRetailSet(string $storeId, array $prices): void
{
    app(SetRetailPriceHandler::class)->handle(new SetRetailPrice($storeId, $prices));
}

/**
 * Remove prices as an actor already acting.
 *
 * @param  list<string>  $variantIds
 */
function pricingRetailRemove(string $storeId, array $variantIds): void
{
    app(RemoveRetailPriceHandler::class)->handle(new RemoveRetailPrice($storeId, $variantIds));
}

describe('setting a retail price', function () {
    it('keeps one price per size per store, in its currency, from staff', function () {
        $sizes = Px::ready(['60 cm', '80 cm'])['variants'];
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa', 'eg']);

        pricingRetailSet(Rx::storeId('sa'), [$sizes[0] => '149.50', $sizes[1] => '200']);
        pricingRetailSet(Rx::storeId('eg'), [$sizes[0] => '1500.25']);

        expect(Rx::retail('sa', $sizes[0]))->toMatchObject(['amount_minor' => 14950, 'currency_code' => 'SAR', 'source' => 'STAFF'])
            ->and(Rx::retail('sa', $sizes[1]))->toMatchObject(['amount_minor' => 20000, 'currency_code' => 'SAR'])
            ->and(Rx::retail('eg', $sizes[0]))->toMatchObject(['amount_minor' => 150025, 'currency_code' => 'EGP'])
            ->and(Rx::retail('eg', $sizes[1]))->toBeNull()
            ->and(Rx::retail('ae', $sizes[0]))->toBeNull();
    });

    it('changes a price that is there, and leaves an equal one as it is', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        DB::table('pricing.retail_prices')->update(['updated_at' => '2026-01-01 00:00:00+00']);

        Rx::set('sa', [$size => '100.00']);

        expect((string) Rx::retail('sa', $size)?->updated_at)->toStartWith('2026-01-01')
            ->and(Fx::audits('pricing.retail_price.set', $size))->toBe(1);

        Rx::set('sa', [$size => '90']);

        expect(Rx::retail('sa', $size)?->amount_minor)->toBe(9000)
            ->and(Fx::audits('pricing.retail_price.set', $size))->toBe(2);
    });

    it('audits each changed size from and to, in the store, under its own name', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);

        expect(Rx::auditChanges('pricing.retail_price.set', $size))->toBe(['amount' => [null, '100.00']]);

        Rx::set('sa', [$size => '89.9']);
        $entry = Rx::lastAudit('pricing.retail_price.set', $size);

        expect(Rx::auditChanges('pricing.retail_price.set', $size))->toBe(['amount' => ['100.00', '89.90']])
            ->and(Fx::audits('pricing.retail_price.set', $size))->toBe(2)
            ->and($entry?->subject_type)->toBe('pricing.retail_price')
            ->and($entry?->store_id)->toBe(Rx::storeId('sa'));
    });

    it('writes the size\'s candidates, the retail price on both lines, and tells Catalog its price', function () {
        $size = Px::ready()['variants'][0];

        Rx::set('sa', [$size => '149.50']);

        expect(Rx::candidates('sa', $size))->toBe([
            'RETAIL 1 14950 SAR BASE - - - -',
            'WHOLESALE 1 14950 SAR BASE - - - -',
        ])->and(Rx::told('sa', $size))->toBe([14950, null, 'SAR'])
            ->and(Rx::told('eg', $size))->toBeNull();
    });

    it('takes the size\'s own id, however it was written', function () {
        $size = Px::ready()['variants'][0];

        Rx::set('sa', [strtoupper($size) => '10']);

        expect(Rx::retail('sa', $size)?->amount_minor)->toBe(1000)
            ->and(Rx::candidates('sa', $size))->toHaveCount(2);
    });

    it('refuses one size given twice with two prices, and takes it given twice with one', function () {
        $size = Px::ready()['variants'][0];

        expect(Rx::invalid(fn () => Rx::set('sa', [$size => '10', strtoupper($size) => '20']))?->attribute)->toBe('variant')
            ->and(Rx::retail('sa', $size))->toBeNull();

        Rx::set('sa', [$size => '10', strtoupper($size) => '10.00']);

        expect(Rx::retail('sa', $size)?->amount_minor)->toBe(1000)
            ->and(Fx::audits('pricing.retail_price.set', $size))->toBe(1);
    });

    it('refuses more decimals than the store\'s currency has, never rounding', function (string $amount) {
        $size = Px::ready()['variants'][0];

        expect(fn () => Rx::set('sa', [$size => $amount]))->toThrow(PriceTooPrecise::class)
            ->and(Rx::retail('sa', $size))->toBeNull();
    })->with(['149.505', '0.001', '1.000']);

    it('takes three decimals in a store whose currency has three', function () {
        $size = Px::ready()['variants'][0];
        $kuwait = Rx::kuwait();
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        pricingRetailSet($kuwait, [$size => '12.345']);

        expect(Rx::retail('kw', $size))->toMatchObject(['amount_minor' => 12345, 'currency_code' => 'KWD'])
            ->and(fn () => pricingRetailSet($kuwait, [$size => '12.3456']))->toThrow(PriceTooPrecise::class);
    });

    it('refuses a price of 0 or less', function (string $amount) {
        $size = Px::ready()['variants'][0];

        expect(fn () => Rx::set('sa', [$size => $amount]))->toThrow(PriceNotPositive::class)
            ->and(Rx::retail('sa', $size))->toBeNull();
    })->with(['0', '0.00', '000', '-5', '-0.01']);

    it('refuses an amount that is not a number, naming the price', function (string $amount) {
        $size = Px::ready()['variants'][0];

        expect(Rx::invalid(fn () => Rx::set('sa', [$size => $amount]))?->attribute)->toBe('amount')
            ->and(Rx::retail('sa', $size))->toBeNull();
    })->with(['', '--5', '+5', '1e3', '1,5', '.5', '5.', 'ten', '99999999999999999999']);

    it('refuses a size that does not exist, is archived or is of an archived product, naming the size', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($ready['variants'][1])));
        $retired = Px::ready();
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($retired['product'])));

        foreach ([strtolower((string) Str::ulid()), 'not-an-id', '123', $ready['variants'][1], $retired['variants'][0]] as $size) {
            expect(Rx::invalid(fn () => Rx::set('sa', [$ready['variants'][0] => '10', $size => '10']))?->attribute)->toBe('variant', "{$size} was priced");
        }

        expect(Rx::retail('sa', $ready['variants'][0]))->toBeNull();
    });

    it('changes nothing when given nothing', function () {
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);
        $queries = Cx::recordQueries();

        pricingRetailSet(Rx::storeId('sa'), []);
        // The recorder is listening: a guard over queries must see one, or it passes over nothing.
        DB::select('SELECT 1');

        // No transaction of its own, and nothing of Pricing's read or written.
        expect(array_filter($queries->getArrayCopy(), static fn (array $query): bool => $query['level'] > 1 || str_contains($query['sql'], '"pricing"')))->toBe([])
            ->and($queries->count())->toBeGreaterThan(0);
    });
});

describe('removing a retail price', function () {
    it('takes the size off sale there: no price, no candidates, Catalog told it has none', function () {
        $sizes = Px::ready(['60 cm', '80 cm'])['variants'];
        Rx::set('sa', [$sizes[0] => '100', $sizes[1] => '120']);
        Rx::set('eg', [$sizes[0] => '900']);

        Rx::remove('sa', [$sizes[0]]);

        expect(Rx::retail('sa', $sizes[0]))->toBeNull()
            ->and(Rx::candidates('sa', $sizes[0]))->toBe([])
            ->and(Rx::told('sa', $sizes[0]))->toBe([null, null, null])
            ->and(Rx::retail('sa', $sizes[1])?->amount_minor)->toBe(12000)
            ->and(Rx::retail('eg', $sizes[0])?->amount_minor)->toBe(90000)
            ->and(Rx::auditChanges('pricing.retail_price.removed', $sizes[0]))->toBe(['amount' => ['100.00', null]]);
    });

    it('changes nothing for a size with no price there, or when given nothing', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('eg', [$size => '900']);

        Rx::remove('sa', [$size, $size]);
        Rx::remove('eg', []);

        expect(Fx::audits('pricing.retail_price.removed'))->toBe(0)
            ->and(Rx::told('sa', $size))->toBeNull()
            ->and(Rx::retail('eg', $size)?->amount_minor)->toBe(90000);
    });

    it('takes the size\'s own id, however it was written', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);

        Rx::remove('sa', [strtoupper($size)]);

        expect(Rx::retail('sa', $size))->toBeNull()
            ->and(Fx::audits('pricing.retail_price.removed', $size))->toBe(1);
    });

    it('keeps an archived size\'s price until it is restored, and refuses a size that does not exist', function () {
        // A ready product keeps one size that is not archived.
        $size = Px::ready(['60 cm', '80 cm'])['variants'][1];
        Rx::set('sa', [$size => '100']);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($size)));

        expect(Rx::invalid(fn () => Rx::remove('sa', [$size]))?->attribute)->toBe('variant')
            ->and(Rx::retail('sa', $size)?->amount_minor)->toBe(10000)
            ->and(Rx::invalid(fn () => Rx::remove('sa', [strtolower((string) Str::ulid())]))?->attribute)->toBe('variant');

        Fx::asSystem(fn () => app(RestoreVariantHandler::class)->handle(new RestoreVariant($size)));
        Rx::remove('sa', [$size]);

        expect(Rx::retail('sa', $size))->toBeNull();
    });
});

describe('who may change a store\'s prices', function () {
    it('lets Edit Prices and Sales in that store, and refuses it in another or without it', function () {
        $size = Px::ready()['variants'][0];

        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['eg']);
        pricingRetailSet(Rx::storeId('eg'), [$size => '900']);

        expect(Rx::retail('eg', $size)?->amount_minor)->toBe(90000)
            ->and(fn () => pricingRetailSet(Rx::storeId('sa'), [$size => '10']))->toThrow(Unauthorized::class)
            ->and(fn () => pricingRetailRemove(Rx::storeId('sa'), [$size]))->toThrow(Unauthorized::class);

        pricingRetailRemove(Rx::storeId('eg'), [$size]);

        expect(Rx::retail('eg', $size))->toBeNull();

        Cx::actAsStaffWith([PricingPermissions::WHOLESALE_EDIT, PricingPermissions::CATEGORY_DISCOUNT_MANAGE], ['*']);

        expect(fn () => pricingRetailSet(Rx::storeId('sa'), [$size => '10']))->toThrow(Unauthorized::class)
            ->and(fn () => pricingRetailRemove(Rx::storeId('sa'), [$size]))->toThrow(Unauthorized::class)
            ->and(Rx::retail('sa', $size))->toBeNull();
    });

    it('refuses a store id that is not one as the permission missing, and an unknown store as invalid', function () {
        $size = Px::ready()['variants'][0];
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['*']);

        expect(fn () => pricingRetailSet('not-a-store', [$size => '10']))->toThrow(Unauthorized::class)
            ->and(Rx::invalid(fn () => pricingRetailSet(StoreId::fromString(strtolower((string) Str::ulid()))->value, [$size => '10']))?->attribute)->toBe('store');
    });

    it('refuses an off store\'s prices to whoever may not switch stores, and lets a Super Admin', function () {
        $size = Px::ready()['variants'][0];
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['*']);

        expect(fn () => pricingRetailSet(Rx::storeId('eg'), [$size => '10']))->toThrow(StoreOff::class)
            ->and(fn () => pricingRetailRemove(Rx::storeId('eg'), [$size]))->toThrow(StoreOff::class);

        pricingRetailSet(Rx::storeId('sa'), [$size => '10']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        pricingRetailSet(Rx::storeId('eg'), [$size => '10']);

        expect(Rx::retail('eg', $size)?->amount_minor)->toBe(1000)
            ->and(Rx::retail('sa', $size)?->amount_minor)->toBe(1000);
    });

    it('refuses a wired store\'s retail prices: they are the provider\'s', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '10']);
        app()->instance(StoreWiring::class, new class implements StoreWiring
        {
            public function isWired(StoreId $store): bool
            {
                return true;
            }

            public function wiredStores(): array
            {
                return array_map(static fn ($store): string => $store->id, app(PlatformApi::class)->allStores());
            }
        });

        expect(fn () => Rx::set('sa', [$size => '20']))->toThrow(ProviderOwnsPrice::class)
            ->and(fn () => Rx::remove('sa', [$size]))->toThrow(ProviderOwnsPrice::class)
            ->and(Rx::retail('sa', $size)?->amount_minor)->toBe(1000);
    });
});

describe('one change, one transaction', function () {
    it('takes the store\'s pricing lock first, inside the change\'s own transaction', function () {
        $size = Px::ready()['variants'][0];
        $queries = Cx::recordQueries();

        Rx::set('sa', [$size => '10']);

        $inside = array_values(array_filter($queries->getArrayCopy(), static fn (array $query): bool => $query['level'] === 2));

        expect($inside[0]['sql'] ?? null)->toContain('pg_advisory_xact_lock')
            ->and($inside[0]['bindings'] ?? null)->toBe(['pricing:store:'.Rx::storeId('sa')]);
    });

    it('tells Catalog inside the change, so a change rolled back tells it nothing', function () {
        $size = Px::ready()['variants'][0];
        $queries = Cx::recordQueries();

        Rx::set('sa', [$size => '10']);

        $pushes = array_values(array_filter($queries->getArrayCopy(), static fn (array $query): bool => str_contains($query['sql'], 'store_variant_facts')));

        // Every write to Catalog's facts ran inside the change's own transaction: level 2 under the
        // test's, or deeper where Catalog opens one of its own.
        expect($pushes)->not->toBe([])
            ->and(array_filter($pushes, static fn (array $query): bool => $query['level'] < 2))->toBe([]);

        // The audit entry is written last: refused, it rolls the whole change back.
        DB::statement('ALTER TABLE platform.audit_entries ADD CONSTRAINT pricing_test_refused CHECK (false) NOT VALID');

        expect(fn () => Rx::set('sa', [$size => '20']))->toThrow(QueryException::class)
            ->and(Rx::retail('sa', $size)?->amount_minor)->toBe(1000)
            ->and(Rx::candidates('sa', $size))->toBe(['RETAIL 1 1000 SAR BASE - - - -', 'WHOLESALE 1 1000 SAR BASE - - - -'])
            ->and(Rx::told('sa', $size))->toBe([1000, null, 'SAR']);
    });
});

describe('the repair', function () {
    it('writes the candidates each change writes, from nothing, in every store, and tells Catalog again', function () {
        $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
        [$a, $b, $c] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));
        Rx::set('sa', [$a => '100', $b => '120']);
        Rx::set('eg', [$a => '900']);
        Rx::remove('sa', [$b]);
        $written = Rx::allCandidates();

        // Candidates stay true (§8 #5): after the changes, the repair finds nothing out of step.
        expect(Fx::asSystem(fn () => app(RebuildPriceCandidatesHandler::class)->handle(new RebuildPriceCandidates)))->toBe(0)
            ->and(Rx::allCandidates())->toBe($written);

        // Out of step: a size's candidates gone, another's wrong, one with no price holding one, and
        // Catalog told a price a size does not have.
        DB::table('pricing.price_candidates')->where('store_id', Rx::storeId('eg'))->delete();
        DB::table('pricing.price_candidates')->where('variant_id', $a)->where('store_id', Rx::storeId('sa'))->update(['amount_minor' => 1]);
        DB::table('pricing.price_candidates')->insert(['store_id' => Rx::storeId('sa'), 'variant_id' => $c, 'mode' => 'RETAIL', 'from_quantity' => 1, 'amount_minor' => 5, 'currency_code' => 'SAR', 'kind' => 'BASE', 'always_wins' => false]);
        DB::table('catalog.store_variant_facts')->where('store_id', Rx::storeId('sa'))->where('variant_id', $b)->update(['price_minor' => 7, 'currency' => 'SAR']);

        expect(Rx::allCandidates())->not->toBe($written);

        $pending = artisan(RebuildPriceCandidatesCommand::NAME);

        if (! $pending instanceof PendingCommand) {
            throw new LogicException('Console output mocking is off, so the command cannot be checked.');
        }

        $pending->expectsOutputToContain('Sizes out of step: 3.')->assertSuccessful()->run();

        expect(Rx::allCandidates())->toBe($written)
            ->and(Rx::told('sa', $b))->toBe([null, null, null])
            ->and(Rx::told('sa', $a))->toBe([10000, null, 'SAR'])
            ->and(Rx::told('eg', $a))->toBe([90000, null, 'EGP'])
            ->and(Fx::asSystem(fn () => app(RebuildPriceCandidatesHandler::class)->handle(new RebuildPriceCandidates)))->toBe(0);
    });

    it('is the system\'s: no role runs it', function () {
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT, PricingPermissions::WHOLESALE_EDIT, PricingPermissions::CATEGORY_DISCOUNT_MANAGE]);

        expect(fn () => app(RebuildPriceCandidatesHandler::class)->handle(new RebuildPriceCandidates))->toThrow(Unauthorized::class);
    });

    it('takes each store\'s lock inside that store\'s own transaction, every store, on or off', function () {
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $seen = Rx::recordTransactions();

        Fx::asSystem(fn () => app(RebuildPriceCandidatesHandler::class)->handle(new RebuildPriceCandidates));

        expect($seen->getArrayCopy())->toBe(array_merge(...array_map(
            static fn (string $code): array => ['begin', 'lock pricing:store:'.Rx::storeId($code), 'commit'],
            ['sa', 'eg', 'ae'],
        )));
    });
});
