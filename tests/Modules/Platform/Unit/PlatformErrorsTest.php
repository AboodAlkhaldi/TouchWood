<?php

declare(strict_types=1);

use Modules\Platform\Domain\Exception\PlatformError;
use Shared\Domain\Error\ErrorCategory;

/**
 * @return list<ReflectionClass<PlatformError>>
 */
function platformErrorClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Platform/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<PlatformError> $class */
        $class = 'Modules\\Platform\\Domain\\Exception\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if (! $reflection->isAbstract()) {
            $classes[] = $reflection;
        }
    }

    return $classes;
}

it('finds the Platform errors', function () {
    expect(count(platformErrorClasses()))->toBeGreaterThanOrEqual(11);
});

it('gives every Platform error a unique platform type and a category', function () {
    $types = [];

    foreach (platformErrorClasses() as $class) {
        $error = $class->newInstanceWithoutConstructor();

        expect($error)->toBeInstanceOf(PlatformError::class)
            ->and($error->type())->toStartWith('platform.')
            ->and($error->category())->toBeInstanceOf(ErrorCategory::class);

        $types[] = $error->type();
    }

    expect($types)->toBe(array_values(array_unique($types)));
});

it('has an Arabic and an English message for every Platform error', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Platform/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Platform/Presentation/lang/en/errors.php';

    foreach (platformErrorClasses() as $class) {
        $key = substr($class->newInstanceWithoutConstructor()->type(), strlen('platform.'));

        expect($ar)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail")
            ->and($en)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail");
    }
});
