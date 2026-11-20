<?php

declare(strict_types=1);

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;

function newCustomer(AccountType $type = AccountType::Individual): Customer
{
    return Customer::register(
        'c1', EmailAddress::of('sara@example.test'), 'hash', 'Sara', 'Ali', $type,
        Language::Arabic, 'store-sa', CUSTOMER_TEST_TERMS, 3, new DateTimeImmutable('2026-09-20 10:00'),
    );
}

/** A Terms and Conditions policy's id, as Content gives it (flags-2026-11.md S3.4). */
const CUSTOMER_TEST_TERMS = '01katerms0000000000000000a';

describe('a customer\'s life (spec §1.1, §4.1)', function () {
    it('starts active, with an unverified email, no phone, and may not order yet', function () {
        $customer = newCustomer();

        expect($customer->status())->toBe(CustomerStatus::Active)
            ->and($customer->emailVerifiedAt())->toBeNull()
            ->and($customer->phone())->toBeNull()
            ->and($customer->mayOrder())->toBeFalse()
            ->and($customer->homeStoreId())->toBe('store-sa')
            ->and($customer->lastStoreId())->toBe('store-sa')
            ->and($customer->termsVersion())->toBeNull()
            ->and($customer->termsPolicyId())->toBe(CUSTOMER_TEST_TERMS)
            ->and($customer->termsPolicyVersion())->toBe(3);
    });

    it('keeps the store\'s Terms and Conditions and its version, or neither, never one without the other (S3.4, F18)', function (?string $policy, ?int $version, ?string $refused) {
        $register = fn (): Customer => Customer::register(
            'c1', EmailAddress::of('sara@example.test'), 'hash', 'Sara', 'Ali', AccountType::Individual,
            Language::Arabic, 'store-sa', $policy, $version, new DateTimeImmutable('2026-09-20 10:00'),
        );

        if ($refused !== null) {
            expect($register)->toThrow(InvalidAccessAttribute::class, $refused);

            return;
        }

        expect($register()->termsPolicyId())->toBe($policy === null ? null : strtolower($policy))
            ->and($register()->termsPolicyVersion())->toBe($version);
    })->with([
        'both' => [strtoupper(CUSTOMER_TEST_TERMS), 1, null],
        'neither: the store had none published' => [null, null, null],
        'a policy without its version' => [CUSTOMER_TEST_TERMS, null, 'terms_policy'],
        'a version without its policy' => [null, 2, 'terms_policy'],
        'version 0' => [CUSTOMER_TEST_TERMS, 0, 'terms_policy_version'],
        'not a policy id' => ['terms-2026', 1, 'terms_policy'],
    ]);

    it('keeps the store\'s Privacy Policy and its version the same way, or neither (owner, 2026-11-20)', function (?string $policy, ?int $version, ?string $refused) {
        $register = fn (): Customer => Customer::register(
            'c1', EmailAddress::of('sara@example.test'), 'hash', 'Sara', 'Ali', AccountType::Individual,
            Language::Arabic, 'store-sa', null, null, new DateTimeImmutable('2026-09-20 10:00'), false, $policy, $version,
        );

        if ($refused !== null) {
            expect($register)->toThrow(InvalidAccessAttribute::class, $refused);

            return;
        }

        expect([$register()->privacyPolicyId(), $register()->privacyPolicyVersion()])->toBe([$policy === null ? null : strtolower($policy), $version]);
    })->with([
        'both' => [strtoupper(CUSTOMER_TEST_TERMS), 2, null],
        'neither' => [null, null, null],
        'a policy without its version' => [CUSTOMER_TEST_TERMS, null, 'privacy_policy'],
        'a version without its policy' => [null, 1, 'privacy_policy'],
        'version 0' => [CUSTOMER_TEST_TERMS, 0, 'privacy_policy_version'],
        'not a policy id' => ['privacy', 1, 'privacy_policy'],
    ]);

    it('may order once both the email and the phone are verified', function () {
        $customer = newCustomer();
        $customer->verifyEmail(new DateTimeImmutable);

        expect($customer->mayOrder())->toBeFalse();

        $customer->verifyPhone(PhoneNumber::of('+966501234567'), new DateTimeImmutable);

        expect($customer->mayOrder())->toBeTrue();
    });

    it('verifies an email once: opening the link again changes nothing', function () {
        $customer = newCustomer();
        $customer->verifyEmail(new DateTimeImmutable('2026-09-20 11:00'));
        $first = $customer->emailVerifiedAt();

        expect($customer->pullChanges())->toBe(['email_verified_at']);

        $customer->verifyEmail(new DateTimeImmutable('2026-09-21 11:00'));

        expect($customer->emailVerifiedAt())->toBe($first)
            ->and($customer->pullChanges())->toBe([]);
    });

    it('keeps a phone once verified, and swaps it only for a newly verified one', function () {
        $customer = newCustomer();
        $customer->verifyPhone(PhoneNumber::of('+966501234567'), new DateTimeImmutable);
        $customer->pullChanges();

        // The same number again: nothing changed, so nothing is audited.
        $customer->verifyPhone(PhoneNumber::of('+966501234567'), new DateTimeImmutable);

        expect($customer->pullChanges())->toBe([]);

        $customer->verifyPhone(PhoneNumber::of('+966509999999'), new DateTimeImmutable);

        expect($customer->phone()?->value)->toBe('+966509999999')
            ->and($customer->phoneVerifiedAt())->not->toBeNull()
            ->and($customer->pullChanges())->toBe(['phone']);
    });

    it('records only real changes to the name and language', function () {
        $customer = newCustomer();

        $customer->updateName('Sara', 'Ali');
        $customer->changeLanguage(Language::Arabic);

        expect($customer->pullChanges())->toBe([]);

        $customer->updateName('Sarah', 'Ali');
        $customer->changeLanguage(Language::English);

        expect($customer->pullChanges())->toBe(['name', 'locale']);
    });

    it('may not order while blocked, or while a deletion waits', function (CustomerStatus $status, ?DateTimeImmutable $deletion, bool $mayOrder) {
        $customer = Customer::reconstitute(
            'c1', EmailAddress::of('sara@example.test'), 'hash', 'Sara', 'Ali', AccountType::Individual, $status,
            new DateTimeImmutable, PhoneNumber::of('+966501234567'), new DateTimeImmutable, Language::Arabic,
            'store-sa', 'store-sa', '2026-01', new DateTimeImmutable, $deletion,
        );

        expect($customer->mayOrder())->toBe($mayOrder);
    })->with([
        'active, nothing pending' => [CustomerStatus::Active, null, true],
        'blocked' => [CustomerStatus::Blocked, null, false],
        'a deletion waiting' => [CustomerStatus::Active, new DateTimeImmutable('2026-10-01'), false],
    ]);

    it('refuses a session version below zero before the database does', function () {
        expect(fn () => Customer::reconstitute(
            'c1', EmailAddress::of('sara@example.test'), 'hash', 'Sara', 'Ali', AccountType::Individual, CustomerStatus::Active,
            null, null, null, Language::Arabic, 'store-sa', 'store-sa', '2026-01', new DateTimeImmutable, null, null, -1,
        ))->toThrow(InvalidAccessAttribute::class, 'session_version');
    });

    it('keeps the account type it was registered with', function () {
        expect(newCustomer(AccountType::Company)->accountType())->toBe(AccountType::Company)
            ->and(newCustomer()->accountType())->toBe(AccountType::Individual);
    });
});
