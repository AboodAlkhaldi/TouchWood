<?php

declare(strict_types=1);

use Modules\Platform\Application\Routing\InMemoryOffStoreViewers;
use Modules\Platform\Public\Contracts\OffStoreViewer;
use Modules\Platform\Public\Contracts\OffStoreViewers;
use Shared\Domain\ValueObject\StoreId;

/*
| Who may see an off store in the shop (platform.md §1.6, §2.7): nobody, unless a module's viewer
| says so - Access's, for a staff view (access.md §1.11).
*/

final class OffStoreViewersTestYes implements OffStoreViewer
{
    public function mayView(StoreId $store): bool
    {
        return $store->value === '01k6aaaaaaaaaaaaaaaaaaaaaa';
    }
}

final class OffStoreViewersTestNo implements OffStoreViewer
{
    public function mayView(StoreId $store): bool
    {
        return false;
    }
}

it('lets nobody see an off store with no viewer registered', function () {
    expect((new InMemoryOffStoreViewers(app()))->mayView(StoreId::fromString('01k6aaaaaaaaaaaaaaaaaaaaaa')))->toBeFalse();
});

it('lets a request see an off store when any registered viewer says yes, and only that store', function () {
    $viewers = new InMemoryOffStoreViewers(app());
    $viewers->register('one', OffStoreViewersTestNo::class);
    $viewers->register('two', OffStoreViewersTestYes::class);

    expect($viewers->mayView(StoreId::fromString('01k6aaaaaaaaaaaaaaaaaaaaaa')))->toBeTrue()
        ->and($viewers->mayView(StoreId::fromString('01k6bbbbbbbbbbbbbbbbbbbbbb')))->toBeFalse();
});

it('refuses a class that is not a viewer, and a module\'s second', function (Closure $register, string $message) {
    expect($register)->toThrow(LogicException::class, $message);
})->with([
    'not a viewer' => [fn () => (new InMemoryOffStoreViewers(app()))->register('one', stdClass::class), 'does not implement'],
    'twice' => [function () {
        $viewers = new InMemoryOffStoreViewers(app());
        $viewers->register('one', OffStoreViewersTestNo::class);
        $viewers->register('one', OffStoreViewersTestYes::class);
    }, 'already has'],
]);

it('is one registry for the whole application, behind Platform\'s public contract', function () {
    expect(app(OffStoreViewers::class))->toBe(app(InMemoryOffStoreViewers::class));
});
