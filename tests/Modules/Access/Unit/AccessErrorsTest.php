<?php

declare(strict_types=1);

use App\Http\ProblemDetails;
use Modules\Access\Domain\Exception\AccessError;
use Modules\Access\Domain\Exception\AddressFormatMissing;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\Exception\CodeRequestTooSoon;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\InvalidCustomerStatus;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\LastSuperAdmin;
use Modules\Access\Domain\Exception\PasswordTooWeak;
use Modules\Access\Domain\Exception\PermissionEscalation;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\ReservedPermission;
use Modules\Access\Domain\Exception\RoleInUse;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Exception\StaffNotEditable;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Exception\TooManyAddresses;
use Modules\Access\Domain\Exception\UnknownPermission;
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
    expect(count(accessErrorClasses()))->toBeGreaterThanOrEqual(28);
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
    'an unknown staff member → 404' => [StaffNotFound::class, 404],
    'a Super Admin, an admin or yourself → 409' => [StaffNotEditable::class, 409],
    'an undeclared or automatic action → 422' => [UnknownPermission::class, 422],
    'a Super Admin action in a role → 422' => [ReservedPermission::class, 422],
    'a malformed value → 422' => [InvalidAccessAttribute::class, 422],
    'another staff member\'s email → 409' => [StaffEmailInUse::class, 409],
    'another account\'s phone → 409' => [PhoneAlreadyInUse::class, 409],
    'a used or expired link → 422' => [InvalidOrExpiredLink::class, 422],
    'a wrong or expired code → 422' => [InvalidCode::class, 422],
    'a code asked for too soon → 409' => [CodeRequestTooSoon::class, 409],
    'a short or leaked password → 422' => [PasswordTooWeak::class, 422],
    'the last active Super Admin → 409' => [LastSuperAdmin::class, 409],
    'a change the account\'s state does not allow → 409' => [InvalidStaffStatus::class, 409],
    'an unknown address → 404' => [AddressNotFound::class, 404],
    'a store with no address form → 409' => [AddressFormatMissing::class, 409],
    'a field the store\'s form refuses → 422' => [InvalidAddress::class, 422],
    'an address book that is full → 409' => [TooManyAddresses::class, 409],
    'a change the account is not in a state for → 409' => [InvalidCustomerStatus::class, 409],
]);
