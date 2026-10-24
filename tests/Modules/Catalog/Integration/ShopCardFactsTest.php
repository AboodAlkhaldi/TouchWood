<?php

declare(strict_types=1);

use Modules\Catalog\Application\Products\InMemoryShopCardFacts;
use Modules\Catalog\Public\Contracts\ShopCardFact;
use Modules\Catalog\Public\Contracts\ShopCardFacts;
use Shared\Domain\ValueObject\StoreId;

/*
| What other modules add to the shop's product cards (catalog.md amendment 32): one registry for the
| application, keys of the form {module}.{key}, each a ShopCardFact registered once, asked once a page with
| the page's products, its answers kept only for the page's own cards.
*/

final class ShopCardFactsTestFact implements ShopCardFact
{
    /** @var list<array{string, ?string, list<string>}> */
    public static array $asked = [];

    public static function forget(): void
    {
        self::$asked = [];
    }

    public function read(StoreId $store, ?string $customerId, array $productIds): array
    {
        self::$asked[] = [$store->value, $customerId, $productIds];

        // Says something of the first, and of a product not on the page.
        return [$productIds[0] => ['for' => $customerId], 'p-elsewhere' => ['for' => 'nobody']];
    }
}

it('is one registry for the whole application, holding Feedback\'s saved hearts', function () {
    $held = (fn (): array => $this->facts)->call(app(InMemoryShopCardFacts::class));

    expect(app(ShopCardFacts::class))->toBe(app(InMemoryShopCardFacts::class))
        ->and(array_keys($held))->toBe(['feedback.saved']);
});

it('asks each fact once with the page\'s products, keeps only the page\'s cards, and asks nobody for no cards', function () {
    $facts = new InMemoryShopCardFacts(app());
    $facts->register('test.one', ShopCardFactsTestFact::class);
    $facts->register('test.two_parts', ShopCardFactsTestFact::class);
    ShopCardFactsTestFact::forget();
    $store = StoreId::fromString('01k6abcdefghjkmnpqrstvwxyz');

    $read = $facts->read($store, 'c1', ['p1', 'p2', 'p1']);
    $none = $facts->read($store, null, []);

    expect($read)->toBe(['p1' => ['test.one' => ['for' => 'c1'], 'test.two_parts' => ['for' => 'c1']]])
        ->and($none)->toBe([])
        ->and(ShopCardFactsTestFact::$asked)->toBe([['01k6abcdefghjkmnpqrstvwxyz', 'c1', ['p1', 'p2']], ['01k6abcdefghjkmnpqrstvwxyz', 'c1', ['p1', 'p2']]]);
});

it('refuses a key not of the form {module}.{key}, a class that is not a fact, and a key twice', function (Closure $register, string $message) {
    expect(fn () => $register(new InMemoryShopCardFacts(app())))->toThrow(LogicException::class, $message);
})->with([
    'no module' => [fn (InMemoryShopCardFacts $f) => $f->register('saved', ShopCardFactsTestFact::class), 'is not {module}.{key}'],
    'not a fact' => [fn (InMemoryShopCardFacts $f) => $f->register('test.one', stdClass::class), 'does not implement'],
    'twice' => [function (InMemoryShopCardFacts $f) {
        $f->register('test.one', ShopCardFactsTestFact::class);
        $f->register('test.one', ShopCardFactsTestFact::class);
    }, 'registered twice'],
]);
