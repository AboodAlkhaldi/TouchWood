<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Application\Home\InMemoryHomeCards;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\HomeCards;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeCardDto;
use Modules\Platform\Public\Dto\HomeCardView;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/*
| The admin home's cards (platform.md §2.6, §9.8; the owner's fix list, point 6): registered as menu
| entries are, and shown only to a reader holding a card's permission in the scope - the store being
| worked in, or every store for All Stores.
*/

/** Says which scope it was asked for: 0 for all stores, 1 for one. */
final class HomeCardsTestCard implements HomeCard
{
    public function data(HomeScope $scope): HomeCardData
    {
        return new HomeCardData([new HomeFigure('scope', $scope->isAllStores() ? 0 : 1)]);
    }
}

final class HomeCardsTestSilentCard implements HomeCard
{
    public function data(HomeScope $scope): ?HomeCardData
    {
        return null;
    }
}

function homeCardsWith(HomeCardDto ...$cards): InMemoryHomeCards
{
    $registry = new InMemoryHomeCards(app());
    $registry->register(...$cards);

    return $registry;
}

/**
 * @param  list<HomeCardView>  $views
 * @return list<string>
 */
function homeCardKeys(array $views): array
{
    return array_map(static fn (HomeCardView $view): string => $view->card->key, $views);
}

describe('registering', function () {
    it('refuses a card registered twice, one drawn by a class that is not a card, and one naming no permission', function (Closure $register, string $message) {
        expect($register)->toThrow(LogicException::class, $message);
    })->with([
        'twice' => [fn () => homeCardsWith(new HomeCardDto('x', 'a', PlatformPermissions::STORE_VIEW, HomeCardsTestCard::class), new HomeCardDto('x', 'a', PlatformPermissions::STORE_VIEW, HomeCardsTestCard::class)), 'twice'],
        'not a card' => [fn () => homeCardsWith(new HomeCardDto('x', 'a', PlatformPermissions::STORE_VIEW, stdClass::class)), 'does not implement'],
        'no permission' => [fn () => homeCardsWith(new HomeCardDto('x', 'a', [], HomeCardsTestCard::class)), 'names no permission'],
        'an empty store-free one' => [fn () => homeCardsWith(new HomeCardDto('x', 'a', [], HomeCardsTestCard::class, storeFree: [''])), 'names no permission'],
    ]);

    it('takes a card guarded by store-free actions alone', function () {
        expect(homeCardsWith(new HomeCardDto('x', 'a', [], HomeCardsTestCard::class, storeFree: [PlatformPermissions::MEDIA_UPLOAD]))->all())->toHaveCount(1);
    });

    it('holds every card registered to its actions\' kinds: per-store ones as per store, store-free ones as store-free', function () {
        $catalog = app(InMemoryPermissionCatalog::class);
        $registry = app(HomeCards::class);
        expect($registry)->toBeInstanceOf(InMemoryHomeCards::class);

        // Listed with the per-store ones, a store-free action would offer All Stores to an admin
        // of one store (the review of P5).
        foreach ($registry->all() as $card) {
            foreach ($card->permissions() as $permission) {
                expect($catalog->definition($permission)?->kind)->toBe(PermissionKind::PerStore, "{$card->module}.{$card->key}: {$permission}");
            }

            foreach ($card->storeFree as $permission) {
                expect($catalog->definition($permission)?->kind)->toBe(PermissionKind::Global, "{$card->module}.{$card->key}: {$permission}");
            }
        }

        expect(array_map(static fn (HomeCardDto $card): string => "{$card->module}.{$card->key}", $registry->all()))
            ->toContain('platform.system', 'b2b.approvals');
    });
});

describe('what a reader is shown', function () {
    it('shows a card in a store where its permission is held, and in no other scope', function () {
        $registry = homeCardsWith(new HomeCardDto('x', 'stores', PlatformPermissions::STORE_UPDATE, HomeCardsTestCard::class));
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']));

        $here = $registry->forCurrentActor(Fx::storeId('sa'), false);

        expect(homeCardKeys($here))->toBe(['stores'])
            ->and($here[0]->data->figures[0]->value)->toBe(1)
            ->and($registry->forCurrentActor(Fx::storeId('eg'), false))->toBe([])
            // No store being worked in: This Store has nothing to speak for.
            ->and($registry->forCurrentActor(null, false))->toBe([])
            // All Stores needs it held for every store.
            ->and($registry->forCurrentActor(Fx::storeId('sa'), true))->toBe([])
            ->and($registry->offersAllStores())->toBeFalse();
    });

    it('offers All Stores to a reader who holds a card\'s permission for every store, and to a Super Admin', function (Closure $reader) {
        $registry = homeCardsWith(new HomeCardDto('x', 'stores', PlatformPermissions::STORE_UPDATE, HomeCardsTestCard::class));
        Fx::actAsStaff($reader());

        $all = $registry->forCurrentActor(null, true);

        expect($registry->offersAllStores())->toBeTrue()
            ->and(homeCardKeys($all))->toBe(['stores'])
            ->and($all[0]->data->figures[0]->value)->toBe(0);
    })->with([
        'every store' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*'])],
        'a Super Admin' => [fn () => Fx::staff(superAdmin: true)],
    ]);

    it('shows a card for any one of its permissions, in its position, and leaves out one with nothing to say', function () {
        $registry = homeCardsWith(
            new HomeCardDto('x', 'second', [PlatformPermissions::STORE_VIEW, PlatformPermissions::SETTINGS_VIEW], HomeCardsTestCard::class, 20),
            new HomeCardDto('x', 'first', PlatformPermissions::SETTINGS_VIEW, HomeCardsTestCard::class, 10),
            new HomeCardDto('x', 'silent', PlatformPermissions::SETTINGS_VIEW, HomeCardsTestSilentCard::class, 5),
            new HomeCardDto('x', 'not_held', PlatformPermissions::STORE_UPDATE, HomeCardsTestCard::class, 1),
        );
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']));

        expect(homeCardKeys($registry->forCurrentActor(Fx::storeId('sa'), false)))->toBe(['first', 'second']);
    });

    it('offers All Stores to no one holding a card\'s action in two stores of three', function () {
        $registry = homeCardsWith(new HomeCardDto('x', 'stores', PlatformPermissions::STORE_UPDATE, HomeCardsTestCard::class));
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa', 'eg']));

        expect($registry->offersAllStores())->toBeFalse()
            ->and($registry->forCurrentActor(null, true))->toBe([])
            ->and(homeCardKeys($registry->forCurrentActor(Fx::storeId('eg'), false)))->toBe(['stores'])
            ->and($registry->forCurrentActor(Fx::storeId('ae'), false))->toBe([]);
    });

    it('shows a card through a store-free action in either scope, and never offers All Stores for it', function () {
        $registry = homeCardsWith(
            new HomeCardDto('x', 'system', PlatformPermissions::STORE_VIEW, HomeCardsTestCard::class, 20, storeFree: [PlatformPermissions::MEDIA_UPLOAD]),
            new HomeCardDto('x', 'stores', PlatformPermissions::STORE_UPDATE, HomeCardsTestCard::class, 10),
        );
        // An admin of one store who may upload media: held "everywhere", as a store-free action is.
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::STORE_UPDATE], ['sa']));

        expect($registry->offersAllStores())->toBeFalse()
            ->and(homeCardKeys($registry->forCurrentActor(Fx::storeId('sa'), false)))->toBe(['stores', 'system'])
            ->and(homeCardKeys($registry->forCurrentActor(Fx::storeId('eg'), false)))->toBe(['system']);
    });

    it('reads the store worked in whatever its letters\' case', function () {
        $registry = homeCardsWith(new HomeCardDto('x', 'stores', PlatformPermissions::STORE_UPDATE, HomeCardsTestCard::class));
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']));

        expect(homeCardKeys($registry->forCurrentActor(strtoupper(Fx::storeId('sa')), false)))->toBe(['stores']);
    });
});
