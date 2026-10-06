<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Exception\CatalogError;
use Shared\Domain\Error\ErrorCategory;

/*
| Catalog's errors (catalog.md §7): each a CatalogError with a unique catalog.* type and a category,
| and a title and a detail in both languages — a refusal never reaches a person as a developer's
| message.
*/

/**
 * @return list<ReflectionClass<CatalogError>>
 */
function catalogErrorClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Catalog/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<CatalogError> $class */
        $class = 'Modules\\Catalog\\Domain\\Exception\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if (! $reflection->isAbstract()) {
            $classes[] = $reflection;
        }
    }

    return $classes;
}

it('finds the Catalog errors', function () {
    // A guard over files must find something, or it passes over nothing.
    expect(count(catalogErrorClasses()))->toBeGreaterThanOrEqual(14);
});

it('gives every Catalog error a unique catalog type and a category', function () {
    $types = [];

    foreach (catalogErrorClasses() as $class) {
        $error = $class->newInstanceWithoutConstructor();

        expect($error)->toBeInstanceOf(CatalogError::class)
            ->and($error->type())->toStartWith('catalog.')
            ->and($error->category())->toBeInstanceOf(ErrorCategory::class);

        $types[] = $error->type();
    }

    expect($types)->toBe(array_values(array_unique($types)));
});

it('has an Arabic and an English title and detail for every Catalog error, and the same field names', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Catalog/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Catalog/Presentation/lang/en/errors.php';

    foreach (catalogErrorClasses() as $class) {
        $key = substr($class->newInstanceWithoutConstructor()->type(), strlen('catalog.'));

        expect($ar)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail")
            ->and($en)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail");
    }

    expect(array_keys($ar['fields']))->toBe(array_keys($en['fields']));
});
