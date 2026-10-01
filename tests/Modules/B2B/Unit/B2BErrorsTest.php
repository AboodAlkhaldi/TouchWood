<?php

declare(strict_types=1);

use Modules\B2B\Domain\Exception\B2BError;
use Shared\Domain\Error\ErrorCategory;

/**
 * @return list<ReflectionClass<B2BError>>
 */
function b2bErrorClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/B2B/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<B2BError> $class */
        $class = 'Modules\\B2B\\Domain\\Exception\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if (! $reflection->isAbstract()) {
            $classes[] = $reflection;
        }
    }

    return $classes;
}

it('finds the B2B errors', function () {
    // A guard over files must find something, or it passes over nothing.
    expect(b2bErrorClasses())->not->toBeEmpty();
});

it('gives every B2B error a unique b2b type and a category', function () {
    $types = [];

    foreach (b2bErrorClasses() as $class) {
        $error = $class->newInstanceWithoutConstructor();

        expect($error)->toBeInstanceOf(B2BError::class)
            ->and($error->type())->toStartWith('b2b.')
            ->and($error->category())->toBeInstanceOf(ErrorCategory::class);

        $types[] = $error->type();
    }

    expect($types)->toBe(array_values(array_unique($types)));
});

it('has an Arabic and an English message for every B2B error, and the same field names', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/B2B/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/B2B/Presentation/lang/en/errors.php';

    foreach (b2bErrorClasses() as $class) {
        $key = substr($class->newInstanceWithoutConstructor()->type(), strlen('b2b.'));

        expect($ar)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail")
            ->and($en)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail");
    }

    expect(array_keys($ar['fields']))->toBe(array_keys($en['fields']));
});
