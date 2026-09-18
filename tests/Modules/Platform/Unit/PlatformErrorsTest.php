<?php

declare(strict_types=1);

use App\Http\ProblemDetails;
use Modules\Platform\Domain\Exception\InvalidMediaAttribute;
use Modules\Platform\Domain\Exception\InvalidMediaVariantsTransition;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Exception\MediaTooLarge;
use Modules\Platform\Domain\Exception\PlatformError;
use Modules\Platform\Domain\Exception\UnsupportedMediaType;
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

it('answers media errors with the HTTP status the spec names', function (string $class, int $status) {
    /** @var class-string<PlatformError> $class */
    $error = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    expect(ProblemDetails::status($error->category()))->toBe($status);
})->with([
    'unsupported type → 415' => [UnsupportedMediaType::class, 415],
    'oversize → 413' => [MediaTooLarge::class, 413],
    'still referenced → 409' => [MediaInUse::class, 409],
    'unknown media → 404' => [MediaNotFound::class, 404],
    'retry of an image that is not stuck → 409' => [InvalidMediaVariantsTransition::class, 409],
    'bad file name or alt text → 422' => [InvalidMediaAttribute::class, 422],
]);

it('has an Arabic and an English message for every Platform error', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Platform/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Platform/Presentation/lang/en/errors.php';

    foreach (platformErrorClasses() as $class) {
        $key = substr($class->newInstanceWithoutConstructor()->type(), strlen('platform.'));

        expect($ar)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail")
            ->and($en)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail");
    }
});
