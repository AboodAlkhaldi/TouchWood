<?php

declare(strict_types=1);

use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Permission\InvalidPermissionDefinition;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

it('keeps each declared permission with its audience and reserved flag', function () {
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare(
        'catalog',
        new PermissionDefinitionDto('catalog.product.update', group: PermissionGroup::Catalog),
        new PermissionDefinitionDto('catalog.brand.delete', reserved: true),
        new PermissionDefinitionDto('catalog.review.write', PermissionAudience::EveryCustomer),
    );

    expect($catalog->definition('catalog.product.update')?->audience)->toBe(PermissionAudience::Role)
        ->and($catalog->definition('catalog.brand.delete')?->reserved)->toBeTrue()
        ->and($catalog->definition('catalog.review.write')?->audience)->toBe(PermissionAudience::EveryCustomer)
        ->and($catalog->definition('catalog.unknown.thing'))->toBeNull()
        ->and(array_map(fn (PermissionDefinitionDto $permission): string => $permission->name, $catalog->all()))
        ->toBe(['catalog.product.update', 'catalog.brand.delete', 'catalog.review.write']);
});

it('refuses a malformed name', function (string $name) {
    (new InMemoryPermissionCatalog)->declare('catalog', new PermissionDefinitionDto($name, group: PermissionGroup::Catalog));
})->throws(InvalidPermissionDefinition::class, 'must look like')->with([
    'only two parts' => ['catalog.update'],
    'capitals' => ['Catalog.Product.Update'],
    'an empty part' => ['catalog..update'],
    'a space' => ['catalog.product update.x'],
]);

it('accepts a resource with several parts', function () {
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare('platform', new PermissionDefinitionDto('platform.media.variants.generate', group: PermissionGroup::Media));

    expect($catalog->definition('platform.media.variants.generate'))->not->toBeNull();
});

it('refuses a permission outside the declaring module', function () {
    (new InMemoryPermissionCatalog)->declare('catalog', new PermissionDefinitionDto('sales.order.cancel', group: PermissionGroup::Orders));
})->throws(InvalidPermissionDefinition::class, 'must start with "catalog."');

it('refuses an action a role can hold with no business area, and an area on one nobody is offered', function (PermissionDefinitionDto $permission, string $message) {
    // The role editor and the menu show every offered action under an area (stage 2b, P2).
    expect(fn () => (new InMemoryPermissionCatalog)->declare('catalog', $permission))
        ->toThrow(InvalidPermissionDefinition::class, $message);
})->with([
    'a role action with no area' => [new PermissionDefinitionDto('catalog.product.update'), 'needs a group'],
    'an area on a reserved action' => [new PermissionDefinitionDto('catalog.brand.delete', reserved: true, group: PermissionGroup::Catalog), 'must have no group'],
    'an area on an automatic action' => [new PermissionDefinitionDto('catalog.review.write', PermissionAudience::EveryCustomer, group: PermissionGroup::Catalog), 'must have no group'],
]);

it('refuses a permission declared twice', function () {
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare('catalog', new PermissionDefinitionDto('catalog.product.update', group: PermissionGroup::Catalog));
    $catalog->declare('catalog', new PermissionDefinitionDto('catalog.product.update', group: PermissionGroup::Catalog));
})->throws(InvalidPermissionDefinition::class, 'already declared');

it('refuses an automatic permission marked reserved', function (PermissionAudience $audience) {
    (new InMemoryPermissionCatalog)->declare('catalog', new PermissionDefinitionDto('catalog.review.write', $audience, reserved: true));
})->throws(InvalidPermissionDefinition::class, 'cannot be both reserved')->with([
    PermissionAudience::EveryStaff,
    PermissionAudience::EveryCustomer,
    PermissionAudience::EveryGuest,
]);

it('offers the role editor only role permissions that are not reserved', function () {
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare(
        'catalog',
        new PermissionDefinitionDto('catalog.product.update', group: PermissionGroup::Catalog),
        new PermissionDefinitionDto('catalog.brand.delete', reserved: true),
        new PermissionDefinitionDto('catalog.review.write', PermissionAudience::EveryCustomer),
    );

    expect(array_map(fn (PermissionDefinitionDto $permission): string => $permission->name, $catalog->assignable()))
        ->toBe(['catalog.product.update']);
});

it('lists the permissions everyone of a kind holds automatically, and none for roles', function () {
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare(
        'catalog',
        new PermissionDefinitionDto('catalog.product.update', group: PermissionGroup::Catalog),
        new PermissionDefinitionDto('catalog.review.write', PermissionAudience::EveryCustomer),
        new PermissionDefinitionDto('catalog.product.browse', PermissionAudience::EveryGuest),
    );

    expect(array_map(fn (PermissionDefinitionDto $permission): string => $permission->name, $catalog->automatic(PermissionAudience::EveryCustomer)))
        ->toBe(['catalog.review.write'])
        ->and($catalog->automatic(PermissionAudience::Role))->toBe([]);
});

it('finds a permission\'s name in the declaring module\'s translations', function () {
    expect((new PermissionDefinitionDto('platform.media.variants.generate'))->labelKey())
        ->toBe('platform::permissions.media.variants.generate');
});

it('declares a permission per store unless it is marked store-free', function () {
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare(
        'catalog',
        new PermissionDefinitionDto('catalog.product.update', group: PermissionGroup::Catalog),
        new PermissionDefinitionDto('catalog.image.upload', kind: PermissionKind::Global, group: PermissionGroup::Catalog),
    );

    expect($catalog->definition('catalog.product.update')?->kind)->toBe(PermissionKind::PerStore)
        ->and($catalog->definition('catalog.image.upload')?->kind)->toBe(PermissionKind::Global);
});

it('refuses a name longer than the database column', function () {
    (new InMemoryPermissionCatalog)->declare('catalog', new PermissionDefinitionDto('catalog.product.'.str_repeat('a', 120), group: PermissionGroup::Catalog));
})->throws(InvalidPermissionDefinition::class, 'longer than 128');

/**
 * A catalog with catalog.product.update declared, as after every module booted.
 */
function catalogWithProductUpdate(): InMemoryPermissionCatalog
{
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare('catalog', new PermissionDefinitionDto('catalog.product.update', group: PermissionGroup::Catalog));

    return $catalog;
}

it('keeps the renames and removals a module declares', function () {
    $catalog = catalogWithProductUpdate();
    $catalog->renamed('catalog', 'catalog.product.edit', 'catalog.product.update');
    $catalog->removed('catalog', 'catalog.product.archive', 'catalog.brand.merge');
    $catalog->verify();

    expect($catalog->renames())->toBe(['catalog.product.edit' => 'catalog.product.update'])
        ->and($catalog->removals())->toBe(['catalog.product.archive', 'catalog.brand.merge']);
});

it('refuses a rename or removal outside the module, malformed, to itself, or twice', function (Closure $declare, string $message) {
    expect(fn () => $declare(catalogWithProductUpdate()))->toThrow(InvalidPermissionDefinition::class, $message);
})->with([
    'another module' => [fn (InMemoryPermissionCatalog $c) => $c->renamed('catalog', 'sales.order.edit', 'catalog.product.update'), 'must start with'],
    'malformed' => [fn (InMemoryPermissionCatalog $c) => $c->removed('catalog', 'catalog.update'), 'must look like'],
    'to itself' => [fn (InMemoryPermissionCatalog $c) => $c->renamed('catalog', 'catalog.product.update', 'catalog.product.update'), 'to itself'],
    'twice' => [function (InMemoryPermissionCatalog $c) {
        $c->renamed('catalog', 'catalog.product.edit', 'catalog.product.update');
        $c->renamed('catalog', 'catalog.product.edit', 'catalog.product.update');
    }, 'already renamed'],
]);

it('refuses, once every module booted, a rename or removal that does not match the declarations', function (Closure $declare, string $message) {
    $catalog = catalogWithProductUpdate();
    $catalog->declare('catalog', new PermissionDefinitionDto('catalog.brand.delete', reserved: true));
    $declare($catalog);

    expect(fn () => $catalog->verify())->toThrow(InvalidPermissionDefinition::class, $message);
})->with([
    'the old name is still declared' => [fn (InMemoryPermissionCatalog $c) => $c->renamed('catalog', 'catalog.product.update', 'catalog.brand.delete'), 'still declared'],
    'the new name is not declared' => [fn (InMemoryPermissionCatalog $c) => $c->renamed('catalog', 'catalog.product.edit', 'catalog.product.change'), 'not a declared permission a role can hold'],
    'the new name is reserved' => [fn (InMemoryPermissionCatalog $c) => $c->renamed('catalog', 'catalog.brand.remove', 'catalog.brand.delete'), 'not a declared permission a role can hold'],
    'renamed and removed' => [function (InMemoryPermissionCatalog $c) {
        $c->renamed('catalog', 'catalog.product.edit', 'catalog.product.update');
        $c->removed('catalog', 'catalog.product.edit');
    }, 'both renamed and removed'],
    'a removed name is still declared' => [fn (InMemoryPermissionCatalog $c) => $c->removed('catalog', 'catalog.product.update'), 'removed but is still declared'],
    'two permissions renamed into one' => [function (InMemoryPermissionCatalog $c) {
        $c->renamed('catalog', 'catalog.product.edit', 'catalog.product.update');
        $c->renamed('catalog', 'catalog.product.change', 'catalog.product.update');
    }, 'the new name of 2 renamed permissions'],
]);
