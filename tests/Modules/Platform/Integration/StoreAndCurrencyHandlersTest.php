<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrency;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrencyHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Domain\Exception\CurrencyAlreadyExists;
use Modules\Platform\Domain\Exception\CurrencyExponentLocked;
use Modules\Platform\Domain\Exception\CurrencyNotFound;
use Modules\Platform\Domain\Exception\StoreAttributeImmutable;
use Modules\Platform\Domain\Exception\StoreCodeTaken;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Events\CurrencyUpdated;
use Modules\Platform\Public\Events\StoreCreated;
use Modules\Platform\Public\Events\StoreUpdated;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\Unauthorized;

uses(RefreshDatabase::class);

function givenCurrency(string $code = 'XTS', ?string $sign = "\u{20C1}"): void
{
    app(CreateCurrencyHandler::class)->handle(new CreateCurrency($code, 2, 'عملة', 'Currency', 'ع.ت', $code, $sign));
}

function givenStore(string $code = 'xa', string $currency = 'XTS', int $position = 1): void
{
    app(CreateStoreHandler::class)->handle(new CreateStore($code, 'متجر '.$code, 'Store '.$code, 'XA', $currency, 1500, 'Asia/Riyadh', $position));
}

function platform(): PlatformApi
{
    return app(PlatformApi::class);
}

describe('creating', function () {
    it('creates a currency and a store that other modules can read', function () {
        givenCurrency();
        givenStore();

        $store = platform()->storeByCode('xa') ?? throw new LogicException('The store was not created.');

        expect($store)->toBeInstanceOf(StoreDto::class)
            ->and($store)->not->toBeInstanceOf(Model::class)
            ->and($store->name->en)->toBe('Store xa')
            ->and($store->currencyExponent)->toBe(2)
            ->and($store->currencySymbol('ar'))->toBe("\u{20C1}")
            ->and($store->taxRateBasisPoints)->toBe(1500)
            ->and(platform()->store($store->storeId())?->code)->toBe('xa')
            ->and(platform()->currency('XTS')?->abbreviation->ar)->toBe('ع.ت');
    });

    it('lists stores by position', function () {
        givenCurrency();
        givenStore('xc', position: 3);
        givenStore('xa', position: 1);
        givenStore('xb', position: 2);

        expect(array_map(fn (StoreDto $store): string => $store->code, platform()->stores()))->toBe(['xa', 'xb', 'xc']);
    });

    it('announces a new store', function () {
        Event::fake([StoreCreated::class]);
        givenCurrency();
        givenStore();

        Event::assertDispatched(StoreCreated::class, fn (StoreCreated $event): bool => $event->storeId === platform()->storeByCode('xa')?->id);
    });

    it('refuses a duplicate currency', function () {
        givenCurrency();
        givenCurrency();
    })->throws(CurrencyAlreadyExists::class);

    it('refuses a store for a currency that does not exist', function () {
        givenStore(currency: 'XXX');
    })->throws(CurrencyNotFound::class);

    it('refuses a store code that is already taken', function () {
        givenCurrency();
        givenStore();
        givenStore();
    })->throws(StoreCodeTaken::class);

    it('writes nothing when the actor is not allowed', function () {
        app()->instance(ActorContext::class, new class implements ActorContext
        {
            public function current(): Actor
            {
                return Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3');
            }
        });

        expect(fn () => givenCurrency())->toThrow(Unauthorized::class)
            ->and(DB::table('platform.currencies')->count())->toBe(0);
    });
});

describe('updating a store', function () {
    it('changes the given attributes, announces which ones, and serves the new values at once', function () {
        givenCurrency();
        givenStore();
        platform()->stores(); // warm the cache
        Event::fake([StoreUpdated::class]);

        app(UpdateStoreHandler::class)->handle(new UpdateStore('xa', nameEn: 'Renamed', taxRateBasisPoints: 1600));

        expect(platform()->storeByCode('xa')?->name->en)->toBe('Renamed')
            ->and(platform()->storeByCode('xa')?->taxRateBasisPoints)->toBe(1600);
        Event::assertDispatched(StoreUpdated::class, fn (StoreUpdated $event): bool => $event->changed === ['name', 'tax_rate_basis_points']);
    });

    it('announces nothing when nothing changed', function () {
        givenCurrency();
        givenStore();
        Event::fake([StoreUpdated::class]);

        app(UpdateStoreHandler::class)->handle(new UpdateStore('xa', taxRateBasisPoints: 1500, newCode: 'xa'));

        Event::assertNotDispatched(StoreUpdated::class);
    });

    it('refuses to change the code, country or currency', function (UpdateStore $command, string $attribute) {
        givenCurrency();
        givenStore();

        expect(fn () => app(UpdateStoreHandler::class)->handle($command))
            ->toThrow(StoreAttributeImmutable::class, $attribute);
    })->with([
        'code' => [new UpdateStore('xa', newCode: 'xb'), 'code'],
        'country' => [new UpdateStore('xa', countryCode: 'XB'), 'country'],
        'currency' => [new UpdateStore('xa', currencyCode: 'XXX'), 'currency'],
    ]);

    it('reports a store that does not exist', function () {
        app(UpdateStoreHandler::class)->handle(new UpdateStore('zz', position: 2));
    })->throws(StoreNotFound::class);
});

describe('updating a currency', function () {
    it('changes the exponent while no store uses the currency', function () {
        givenCurrency();

        app(UpdateCurrencyHandler::class)->handle(new UpdateCurrency('XTS', exponent: 3));

        expect(platform()->currency('XTS')?->exponent)->toBe(3);
    });

    it('locks the exponent once a store uses the currency', function () {
        givenCurrency();
        givenStore();

        app(UpdateCurrencyHandler::class)->handle(new UpdateCurrency('XTS', exponent: 3));
    })->throws(CurrencyExponentLocked::class);

    it('falls back to letters everywhere once the sign is cleared', function () {
        givenCurrency();
        givenStore();
        platform()->stores(); // warm the cache
        Event::fake([CurrencyUpdated::class]);

        app(UpdateCurrencyHandler::class)->handle(new UpdateCurrency('XTS', clearSign: true));

        expect(platform()->currency('XTS')?->displaySymbol('ar'))->toBe('ع.ت')
            ->and(platform()->storeByCode('xa')?->currencySymbol('en'))->toBe('XTS');
        Event::assertDispatched(CurrencyUpdated::class, fn (CurrencyUpdated $event): bool => $event->changed === ['sign']);
    });

    it('reports a currency that does not exist', function () {
        app(UpdateCurrencyHandler::class)->handle(new UpdateCurrency('XXX', nameEn: 'Nothing'));
    })->throws(CurrencyNotFound::class);
});
