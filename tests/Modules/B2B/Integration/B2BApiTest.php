<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\B2B\Application\Settings\BankAccountSettings;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| What other modules may ask B2B (b2b.md §2.1, §2.2): the company behind an account, its status, and
| whether it may order. Ids in, DTOs out, never its documents.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('knows no company for an individual account, or for a company account whose application is still a draft', function (Closure $account) {
    $customerId = $account();
    $api = app(B2BApi::class);

    expect($api->company($customerId))->toBeNull()
        ->and($api->status($customerId))->toBeNull()
        ->and($api->isApproved($customerId))->toBeFalse();
})->with([
    'an individual' => [fn () => Fx::customer(strtolower((string) Str::ulid()).'@example.test')],
    'only a draft' => [function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::storedDraft($customerId);

        return $customerId;
    }],
    'no account at all' => [fn () => strtolower((string) Str::ulid())],
]);

it('gives the company with its home store\'s type names, its status, and no documents', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::sent($customerId);
    $type = app(CompanyTypeRepository::class)->find((string) $company->details()->type->typeId);

    $dto = app(B2BApi::class)->company($customerId);

    expect($dto?->id)->toBe($company->id())
        ->and($dto?->customerId)->toBe($customerId)
        ->and($dto?->name)->toBe('Al Noor Trading')
        ->and($dto?->typeNameAr)->toBe($type?->name()->ar)
        ->and($dto?->typeNameEn)->toBe($type?->name()->en)
        ->and($dto?->typeNameEn)->not->toBeNull()
        ->and($dto?->status)->toBe(CompanyStatus::Pending)
        ->and($dto?->statusReason)->toBeNull()
        ->and(array_keys(get_object_vars($dto ?? new stdClass)))->toBe([
            'id', 'customerId', 'name', 'typeNameAr', 'typeNameEn', 'status', 'statusReason',
        ]);
});

it('gives a company still "Other" no type yet, and never its words (amendment 13(b))', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::sent($customerId);
    $company->correctType(CompanyTypeChoice::other('Cooperative of one'), B2BFixtures::companyTypes());
    app(CompanyRepository::class)->update($company);

    $dto = app(B2BApi::class)->company($customerId);

    expect($dto?->typeNameAr)->toBeNull()
        ->and($dto?->typeNameEn)->toBeNull()
        ->and(serialize($dto))->not->toContain('Cooperative');
});

/**
 * A store's three bank settings, as its staff fill them in. Named for this file: a function declared
 * in a Pest file is global to the whole suite.
 */
function b2bApiBank(string $storeCode, string $iban, string $bank, string $holder): void
{
    foreach ([BankAccountSettings::IBAN => $iban, BankAccountSettings::BANK => $bank, BankAccountSettings::HOLDER => $holder] as $key => $value) {
        app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, $storeCode, $value));
    }
}

describe('the bank account a store\'s companies transfer to (amendment 13(c))', function () {
    it('gives the store\'s own account once all three are filled in', function () {
        b2bApiBank('sa', 'GB82 WEST 1234 5698 7654 32', 'Al Noor Bank', 'TouchWood Trading');
        b2bApiBank('eg', 'DE89 3704 0044 0532 0130 00', 'Another Bank', 'Another Holder');

        $account = app(B2BApi::class)->bankAccount(Fx::storeId('sa'));

        expect([$account?->iban, $account?->bank, $account?->holder])->toBe(['GB82 WEST 1234 5698 7654 32', 'Al Noor Bank', 'TouchWood Trading']);
    });

    it('gives none while any one of the three is empty: bank transfer is temporarily off', function (string $empty) {
        b2bApiBank('sa', 'GB82 WEST 1234 5698 7654 32', 'Al Noor Bank', 'TouchWood Trading');
        app(UpdateSettingHandler::class)->handle(new UpdateSetting($empty, 'sa', ''));

        expect(app(B2BApi::class)->bankAccount(Fx::storeId('sa')))->toBeNull();
    })->with([BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER]);

    it('gives none for a store that has entered nothing, or that does not exist', function () {
        b2bApiBank('sa', 'GB82 WEST 1234 5698 7654 32', 'Al Noor Bank', 'TouchWood Trading');

        expect(app(B2BApi::class)->bankAccount(Fx::storeId('eg')))->toBeNull()
            ->and(app(B2BApi::class)->bankAccount(strtolower((string) Str::ulid())))->toBeNull();
    });

    it('refuses an id that is not a store id: a caller\'s bug', function () {
        app(B2BApi::class)->bankAccount('sa');
    })->throws(InvalidArgumentException::class);
});

it('still names a type that was deactivated since: the company keeps it', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::approved($customerId);
    $type = app(CompanyTypeRepository::class)->find((string) $company->details()->type->typeId);
    B2BFixtures::deactivate($type ?? throw new LogicException('The type is gone.'), InactiveTypeDisplay::Hidden);

    expect(app(B2BApi::class)->company($customerId)?->typeNameEn)->toBe($type->name()->en);
});

it('says the status, with its reason, and that only an approved company may order', function (Closure $arrange, CompanyStatus $status, ?string $reason, bool $approved) {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $arrange($customerId);
    $api = app(B2BApi::class);

    expect($api->status($customerId))->toBe($status)
        ->and($api->company($customerId)?->status)->toBe($status)
        ->and($api->company($customerId)?->statusReason)->toBe($reason)
        ->and($api->isApproved($customerId))->toBe($approved);
})->with([
    'waiting' => [fn (string $customerId) => B2BFixtures::sent($customerId), CompanyStatus::Pending, null, false],
    'approved' => [fn (string $customerId) => B2BFixtures::approved($customerId), CompanyStatus::Approved, null, true],
    'rejected' => [fn (string $customerId) => B2BFixtures::rejected($customerId), CompanyStatus::Rejected, 'The CR number does not match the certificate.', false],
    'suspended after its approval' => [
        fn (string $customerId) => B2BFixtures::suspend(B2BFixtures::approved($customerId)[0]),
        CompanyStatus::Suspended, 'Suspended while the tax number is checked.', false,
    ],
]);
