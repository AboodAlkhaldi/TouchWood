<?php

declare(strict_types=1);

use App\Http\ProblemDetails;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Shared\Domain\ValueObject\MoneyException;

it('maps every error category to its HTTP status', function (ErrorCategory $category, int $status) {
    expect(ProblemDetails::status($category))->toBe($status);
})->with([
    [ErrorCategory::NotFound, 404],
    [ErrorCategory::Forbidden, 403],
    [ErrorCategory::Conflict, 409],
    [ErrorCategory::Invalid, 422],
    [ErrorCategory::Unsupported, 415],
    [ErrorCategory::TooLarge, 413],
]);

it('treats a missing permission as a forbidden shared error', function () {
    $error = new Unauthorized('platform.store.update');

    expect($error)->toBeInstanceOf(DomainError::class)
        ->and($error->type())->toBe('shared.unauthorized')
        ->and($error->category())->toBe(ErrorCategory::Forbidden)
        ->and($error->permission)->toBe('platform.store.update');
});

it('treats invalid money as an invalid shared error', function () {
    $error = MoneyException::overflow();

    expect($error)->toBeInstanceOf(DomainError::class)
        ->and($error->type())->toBe('shared.invalid_money')
        ->and($error->category())->toBe(ErrorCategory::Invalid);
});
