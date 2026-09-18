<?php

declare(strict_types=1);

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

it('refuses the values of an attribute named like personal data', function (string $attribute) {
    AuditChanges::none()->changed($attribute, 'old', 'new');
})->throws(InvalidArgumentException::class, 'personal()')->with([
    'email', 'customer_email', 'contact_phone', 'mobile', 'billing_address', 'ip_address',
    'first_name', 'middle_name', 'last_name', 'family_name', 'given_name', 'full_name',
    'national_id', 'national_id_number', 'iqama', 'iqama_number', 'passport', 'passport_number',
    'birth_date', 'birthdate', 'date_of_birth', 'iban', 'Email', 'e_mail', 'emails',
    'phone_number', 'mobile_number', 'shipping_addresses', 'address_line_1', 'address_line1',
    'whatsapp', 'whatsapp_number', 'telephone', 'phone_numbers', 'phone_e164', 'street',
    'billing_address_street', 'id_number', 'recipient_name', 'customer_name', 'contact_name',
    'surname', 'firstname', 'lastname', 'dob',
    // camelCase is read as snake_case.
    'phoneNumber', 'firstName', 'emailAddress',
]);

it('still records the values of attributes that are not personal', function (string $attribute) {
    expect(AuditChanges::none()->changed($attribute, 'old', 'new')->toArray())->toBe([$attribute => ['old', 'new']]);
})->with([
    // A store's or currency's name is not a person's; neither is a verification time.
    'name', 'email_verified_at', 'phone_verified', 'status', 'code', 'address_type',
    'product_name', 'store_name', 'emailVerifiedAt',
    // Not refused by name (owner's decision): a shipping zone's city is not personal.
    'city', 'postal_code',
]);

it('offers no way to pass a value for a personal field', function () {
    expect((new ReflectionMethod(AuditChanges::class, 'personal'))->getNumberOfParameters())->toBe(1);
});

it('knows when nothing changed', function () {
    expect(AuditChanges::none()->isEmpty())->toBeTrue()
        ->and(AuditChanges::none()->personal('name')->isEmpty())->toBeFalse();
});
