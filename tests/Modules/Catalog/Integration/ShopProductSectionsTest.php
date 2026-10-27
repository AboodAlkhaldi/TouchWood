<?php

declare(strict_types=1);

use Modules\Catalog\Application\Products\InMemoryShopProductSections;
use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Contracts\ShopProductSections;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;

/*
| The shop product page's sections (catalog.md amendment 26(b)): one registry for the application,
| keys of the form {module}.{key}, each class a ShopProductSection registered once, every section
| asked with the same visit — the store, language, product, customer and every section's place.
*/

final class ShopProductSectionsTestSection implements ShopProductSection
{
    /** @var list<ShopProductVisitDto> */
    public static array $asked = [];

    public static function forget(): void
    {
        self::$asked = [];
    }

    public function read(ShopProductVisitDto $visit): ?array
    {
        self::$asked[] = $visit;

        return $visit->customerId === null ? null : ['for' => $visit->customerId];
    }
}

it('is one registry for the whole application, holding Pricing\'s section and the three Feedback registers, in order', function () {
    $held = (fn (): array => $this->sections)->call(app(InMemoryShopProductSections::class));

    expect(app(ShopProductSections::class))->toBe(app(InMemoryShopProductSections::class))
        ->and(array_keys($held))->toBe(['pricing.wholesale', 'feedback.reviews', 'feedback.questions', 'feedback.wishlist']);
});

it('tells every section who is looking and which of the page\'s sizes they can buy wholesale (amendment 35)', function () {
    $sections = new InMemoryShopProductSections(app());
    $sections->register('test.one', ShopProductSectionsTestSection::class);
    ShopProductSectionsTestSection::forget();
    $store = StoreId::fromString('01k6abcdefghjkmnpqrstvwxyz');

    $sections->read($store, 'en', 'p1', null, [], [], true, ShopperKind::Company, ['v1', 'v2']);
    $sections->read($store, 'en', 'p1', null, []);

    expect([ShopProductSectionsTestSection::$asked[0]->viewer, ShopProductSectionsTestSection::$asked[0]->wholesaleSizes])->toBe([ShopperKind::Company, ['v1', 'v2']])
        ->and([ShopProductSectionsTestSection::$asked[1]->viewer, ShopProductSectionsTestSection::$asked[1]->wholesaleSizes])->toBe([ShopperKind::Guest, []]);
});

it('asks every section with the same visit, and carries what each answers', function () {
    $sections = new InMemoryShopProductSections(app());
    $sections->register('test.one', ShopProductSectionsTestSection::class);
    $sections->register('test.two_parts', ShopProductSectionsTestSection::class);
    ShopProductSectionsTestSection::forget();
    $store = StoreId::fromString('01k6abcdefghjkmnpqrstvwxyz');

    $guest = $sections->read($store, 'ar', 'p1', null, []);
    $signedIn = $sections->read($store, 'en', 'p1', 'c1', ['test.one' => 'x']);

    expect($guest)->toBe(['test.one' => null, 'test.two_parts' => null])
        ->and($signedIn)->toBe(['test.one' => ['for' => 'c1'], 'test.two_parts' => ['for' => 'c1']])
        ->and(count(ShopProductSectionsTestSection::$asked))->toBe(4)
        ->and(ShopProductSectionsTestSection::$asked[2]->after)->toBe(['test.one' => 'x'])
        ->and([ShopProductSectionsTestSection::$asked[2]->locale, ShopProductSectionsTestSection::$asked[2]->store->value])->toBe(['en', '01k6abcdefghjkmnpqrstvwxyz']);
});

it('gives every section the filters the address names, beside where each goes on from (amendment 31)', function () {
    $sections = new InMemoryShopProductSections(app());
    $sections->register('test.one', ShopProductSectionsTestSection::class);
    ShopProductSectionsTestSection::forget();
    $store = StoreId::fromString('01k6abcdefghjkmnpqrstvwxyz');

    $sections->read($store, 'en', 'p1', 'c1', ['test.one' => 'x'], ['test.one' => '4']);
    $sections->read($store, 'en', 'p1', 'c1', []);

    expect(ShopProductSectionsTestSection::$asked[0]->filters)->toBe(['test.one' => '4'])
        ->and(ShopProductSectionsTestSection::$asked[0]->after)->toBe(['test.one' => 'x'])
        ->and(ShopProductSectionsTestSection::$asked[1]->filters)->toBe([]);
});

it('refuses a key not of the form {module}.{key}, a class that is not a section, and a key twice', function (Closure $register, string $message) {
    expect(fn () => $register(new InMemoryShopProductSections(app())))->toThrow(LogicException::class, $message);
})->with([
    'no module' => [fn (InMemoryShopProductSections $s) => $s->register('reviews', ShopProductSectionsTestSection::class), 'is not {module}.{key}'],
    'capitals' => [fn (InMemoryShopProductSections $s) => $s->register('Feedback.reviews', ShopProductSectionsTestSection::class), 'is not {module}.{key}'],
    'not a section' => [fn (InMemoryShopProductSections $s) => $s->register('test.one', stdClass::class), 'does not implement'],
    'twice' => [function (InMemoryShopProductSections $s) {
        $s->register('test.one', ShopProductSectionsTestSection::class);
        $s->register('test.one', ShopProductSectionsTestSection::class);
    }, 'registered twice'],
]);
