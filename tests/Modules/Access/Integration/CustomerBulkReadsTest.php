<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Enums\AccountType;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Two reads for Promotions' segments (amendment 70; promotions.md §1.5, §2.3): many customers at once,
| and the customers registered in a store, of one account type, not anonymized, keyset-paged — each a
| fixed number of queries however many.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function bulkAccess(): AccessApi
{
    return app(AccessApi::class);
}

it('reads many customers keyed by id, as one would be read, leaving out ids that name nobody', function () {
    $sara = Fx::customer('sara@example.test');
    $omar = Fx::customer('omar@example.test', 'eg', 'company');

    $found = bulkAccess()->customers([strtoupper($sara), $omar, $sara, '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id']);

    expect(array_keys($found))->toEqualCanonicalizing([$sara, $omar])
        ->and($found[$sara])->toBeInstanceOf(CustomerDto::class)
        ->and($found[$sara])->toEqual(bulkAccess()->customer($sara))
        ->and($found[$omar]->accountType)->toBe(AccountType::Company)
        ->and(bulkAccess()->customers([]))->toBe([]);
});

it('pages the customers registered in a store by type, leaving out other stores, other types and the anonymized', function () {
    $sa = StoreId::fromString(Fx::storeId('sa'));
    $people = [];

    foreach (range(1, 5) as $n) {
        $people[] = Fx::customer("person{$n}@example.test");
    }

    $company = Fx::customer('firm@example.test', 'sa', 'company');
    Fx::customer('elsewhere@example.test', 'eg');
    DB::table('access.customers')->where('id', $people[4])->update(['anonymized_at' => now()]);
    sort($people);
    $live = array_values(array_filter($people, static fn (string $id): bool => DB::table('access.customers')->where('id', $id)->whereNull('anonymized_at')->exists()));

    $first = bulkAccess()->customerIdsInStore($sa, AccountType::Individual, null, 2);
    $second = bulkAccess()->customerIdsInStore($sa, AccountType::Individual, $first[1], 2);
    $last = bulkAccess()->customerIdsInStore($sa, AccountType::Individual, $second[1] ?? null, 2);

    expect([...$first, ...$second, ...$last])->toBe($live)
        ->and($last)->toHaveCount(0)
        ->and(bulkAccess()->customerIdsInStore($sa, AccountType::Company, null, 10))->toBe([$company])
        ->and(fn () => bulkAccess()->customerIdsInStore($sa, AccountType::Individual, null, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => bulkAccess()->customerIdsInStore($sa, AccountType::Individual, null, 1001))->toThrow(InvalidArgumentException::class);
});

it('answers in the same number of queries however many customers', function () {
    $few = [Fx::customer('a@example.test')];
    $many = [...$few, Fx::customer('b@example.test'), Fx::customer('c@example.test'), Fx::customer('d@example.test')];
    $sa = StoreId::fromString(Fx::storeId('sa'));
    $count = static function (callable $read): int {
        $queries = Cx::recordQueries();
        $read();

        return count($queries);
    };

    expect($count(fn () => bulkAccess()->customers($few)))->toBe($count(fn () => bulkAccess()->customers($many)))
        ->and($count(fn () => bulkAccess()->customerIdsInStore($sa, AccountType::Individual, null, 1)))->toBe($count(fn () => bulkAccess()->customerIdsInStore($sa, AccountType::Individual, null, 100)));
});
