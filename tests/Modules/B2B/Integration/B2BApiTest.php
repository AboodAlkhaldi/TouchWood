<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Enums\CompanyStatus;
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
        ->and($dto?->typeOther)->toBeNull()
        ->and($dto?->status)->toBe(CompanyStatus::Pending)
        ->and($dto?->statusReason)->toBeNull()
        ->and(array_keys(get_object_vars($dto ?? new stdClass)))->toBe([
            'id', 'customerId', 'name', 'typeNameAr', 'typeNameEn', 'typeOther', 'status', 'statusReason',
        ]);
});

it('gives a company that wrote its own type those words, and no type names', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::sent($customerId);
    $company->correctType(CompanyTypeChoice::other('Cooperative'), B2BFixtures::companyTypes());
    app(CompanyRepository::class)->update($company);

    $dto = app(B2BApi::class)->company($customerId);

    expect($dto?->typeOther)->toBe('Cooperative')
        ->and($dto?->typeNameAr)->toBeNull()
        ->and($dto?->typeNameEn)->toBeNull();
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
