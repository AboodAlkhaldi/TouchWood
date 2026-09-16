<?php

use Modules\Platform\Public\Dto\AuditChanges;

it('records the old and new value of an ordinary attribute', function () {
    $changes = AuditChanges::none()->changed('tax_rate_basis_points', 1500, 1600);

    expect($changes->toArray())->toBe(['tax_rate_basis_points' => [1500, 1600]]);
});

it('records only that a personal field changed, never its value', function () {
    $changes = AuditChanges::none()
        ->personal('email')
        ->personal('phone');

    expect($changes->toArray())->toBe(['email' => 'changed', 'phone' => 'changed']);
});

it('offers no way to pass a value for a personal field', function () {
    expect((new ReflectionMethod(AuditChanges::class, 'personal'))->getNumberOfParameters())->toBe(1);
});

it('knows when nothing changed', function () {
    expect(AuditChanges::none()->isEmpty())->toBeTrue()
        ->and(AuditChanges::none()->personal('name')->isEmpty())->toBeFalse();
});
