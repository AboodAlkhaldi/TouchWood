<?php

declare(strict_types=1);

use App\Http\ProblemDetails;
use Modules\Access\Domain\Exception\AccessError;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\Exception\PermissionEscalation;
use Modules\Access\Domain\Exception\RoleInUse;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Shared\Domain\Error\ErrorCategory;

/**
 * @return list<ReflectionClass<AccessError>>
 */
function accessErrorClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Access/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<AccessError> $class */
        $class = 'Modules\\Access\\Domain\\Exception\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if (! $reflection->isAbstract()) {
            $classes[] = $reflection;
        }
    }

    return $classes;
}

it('finds the Access errors', function () {
    expect(count(accessErrorClasses()))->toBeGreaterThanOrEqual(11);
});

it('gives every Access error a unique access type and a category', function () {
    $types = [];

    foreach (accessErrorClasses() as $class) {
        $error = $class->newInstanceWithoutConstructor();

        expect($error)->toBeInstanceOf(AccessError::class)
            ->and($error->type())->toStartWith('access.')
            ->and($error->category())->toBeInstanceOf(ErrorCategory::class);

        $types[] = $error->type();
    }

    expect($types)->toBe(array_values(array_unique($types)));
});

it('has an Arabic and an English message for every Access error', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Access/Presentation/lang/ar/errors.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Access/Presentation/lang/en/errors.php';

    foreach (accessErrorClasses() as $class) {
        $key = substr($class->newInstanceWithoutConstructor()->type(), strlen('access.'));

        expect($ar)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail")
            ->and($en)->toHaveKey("{$key}.title")->toHaveKey("{$key}.detail");
    }
});

it('answers with the HTTP status the spec names', function (string $class, int $status) {
    /** @var class-string<AccessError> $class */
    $error = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    expect(ProblemDetails::status($error->category()))->toBe($status);
})->with([
    'granting more than you hold → 403' => [PermissionEscalation::class, 403],
    'an admin role by an admin → 403' => [SuperAdminOnly::class, 403],
    'a held role deleted without a replacement → 409' => [RoleInUse::class, 409],
    'a taken name → 409' => [RoleNameTaken::class, 409],
    'a management action in a staff role → 422' => [AdminOnlyPermission::class, 422],
    'an unknown role → 404' => [RoleNotFound::class, 404],
]);
