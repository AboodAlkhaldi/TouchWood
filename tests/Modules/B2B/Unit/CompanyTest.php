<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyDetails;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Public\Enums\CompanyStatus;

/*
| The company's state machine (b2b.md §4.1, amendment 3).
*/

const COMPANY_TEST_STAFF = '01j8z3k4m5n6p7q8r9s0t1v2w9';

function companyTestDetails(string $name = 'Al Noor Trading'): CompanyDetails
{
    return new CompanyDetails(
        CompanyName::of($name),
        CompanyTypeChoice::listed('01j8z3k4m5n6p7q8r9s0t1v2w1'),
        RegistrationNumber::of('cr_number', '1010123456'),
        RegistrationNumber::of('tax_number', '300123456700003'),
        CompanyAddress::of("King Fahd Road\nRiyadh"),
    );
}

/**
 * A company in the status asked for, reached the way a real one would be.
 */
function companyIn(CompanyStatus $status): Company
{
    $company = Company::fromFirstApplication('01j8z3k4m5n6p7q8r9s0t1v2w3', '01j8z3k4m5n6p7q8r9s0t1v2w4', '01j8z3k4m5n6p7q8r9s0t1v2w5', companyTestDetails(), CarbonImmutable::now());

    match ($status) {
        CompanyStatus::Pending => null,
        CompanyStatus::Approved => $company->approve(COMPANY_TEST_STAFF, CarbonImmutable::now()),
        CompanyStatus::Rejected => $company->reject(COMPANY_TEST_STAFF, Remark::of('reason', 'The CR number does not match.'), CarbonImmutable::now()),
        CompanyStatus::Suspended => $company->suspend(COMPANY_TEST_STAFF, Remark::of('reason', 'A transfer was reversed.'), CarbonImmutable::now()),
    };
    $company->pullChanges();

    return $company;
}

describe('coming to exist', function () {
    it('exists only once an application is sent, and then it is PENDING', function () {
        $company = Company::fromFirstApplication('01j8z3k4m5n6p7q8r9s0t1v2w3', '01j8z3k4m5n6p7q8r9s0t1v2w4', '01j8z3k4m5n6p7q8r9s0t1v2w5', companyTestDetails(), CarbonImmutable::parse('2026-09-27 10:00'));

        expect($company->status())->toBe(CompanyStatus::Pending)
            ->and($company->mayOrder())->toBeFalse()
            ->and($company->statusChangedAt()?->format('Y-m-d H:i'))->toBe('2026-09-27 10:00')
            // The customer sent it: no staff member changed anything.
            ->and($company->statusChangedBy())->toBeNull();
    });
});

describe('staff deciding', function () {
    it('approves a pending company, which may then order — and nothing else may', function () {
        $company = companyIn(CompanyStatus::Pending);
        $company->approve(COMPANY_TEST_STAFF, CarbonImmutable::now());

        expect($company->status())->toBe(CompanyStatus::Approved)
            ->and($company->mayOrder())->toBeTrue()
            ->and($company->statusChangedBy())->toBe(COMPANY_TEST_STAFF)
            ->and($company->pullChanges())->toBe(['status']);

        foreach ([CompanyStatus::Pending, CompanyStatus::Rejected, CompanyStatus::Suspended] as $status) {
            expect(companyIn($status)->mayOrder())->toBeFalse();
        }
    });

    it('rejects a pending company with its reason', function () {
        $company = companyIn(CompanyStatus::Pending);
        $company->reject(COMPANY_TEST_STAFF, Remark::of('reason', "The CR number does not match.\nPlease check it."), CarbonImmutable::now());

        expect($company->status())->toBe(CompanyStatus::Rejected)
            ->and($company->statusReason()?->value)->toBe("The CR number does not match.\nPlease check it.");
    });

    it('decides only a company that has an application waiting', function (CompanyStatus $status, Closure $decide) {
        expect(fn () => $decide(companyIn($status)))->toThrow(InvalidCompanyStatus::class);
    })->with([
        'approving an approved one' => [CompanyStatus::Approved, fn (Company $company) => $company->approve(COMPANY_TEST_STAFF, CarbonImmutable::now())],
        // No arrow from APPROVED to REJECTED: staff who must stop an approved company suspend it
        // (owner, 2026-09-27).
        'rejecting an approved one' => [CompanyStatus::Approved, fn (Company $company) => $company->reject(COMPANY_TEST_STAFF, Remark::of('reason', 'No.'), CarbonImmutable::now())],
        'approving a rejected one' => [CompanyStatus::Rejected, fn (Company $company) => $company->approve(COMPANY_TEST_STAFF, CarbonImmutable::now())],
        'rejecting a suspended one' => [CompanyStatus::Suspended, fn (Company $company) => $company->reject(COMPANY_TEST_STAFF, Remark::of('reason', 'No.'), CarbonImmutable::now())],
        'approving a suspended one' => [CompanyStatus::Suspended, fn (Company $company) => $company->approve(COMPANY_TEST_STAFF, CarbonImmutable::now())],
    ]);
});

describe('suspending and reinstating', function () {
    it('suspends from any status, and reinstating returns it to that status — never simply to PENDING', function (CompanyStatus $from) {
        $company = companyIn($from);
        $company->suspend(COMPANY_TEST_STAFF, Remark::of('reason', 'A transfer was reversed.'), CarbonImmutable::now());

        expect($company->status())->toBe(CompanyStatus::Suspended)
            ->and($company->statusBeforeSuspension())->toBe($from)
            ->and($company->mayOrder())->toBeFalse();

        $company->reinstate(COMPANY_TEST_STAFF, Remark::of('reason', 'The transfer went through.'), CarbonImmutable::now());

        expect($company->status())->toBe($from)
            ->and($company->statusBeforeSuspension())->toBeNull()
            // A reason for the reinstatement too, so the history reads as a conversation.
            ->and($company->statusReason()?->value)->toBe('The transfer went through.');
    })->with([CompanyStatus::Pending, CompanyStatus::Approved, CompanyStatus::Rejected]);

    it('refuses to suspend a suspended company, or reinstate one that is not', function () {
        expect(fn () => companyIn(CompanyStatus::Suspended)->suspend(COMPANY_TEST_STAFF, Remark::of('reason', 'Again.'), CarbonImmutable::now()))
            ->toThrow(InvalidCompanyStatus::class)
            ->and(fn () => companyIn(CompanyStatus::Approved)->reinstate(COMPANY_TEST_STAFF, Remark::of('reason', 'Why?'), CarbonImmutable::now()))
            ->toThrow(InvalidCompanyStatus::class);
    });
});

describe('a new application from an existing company', function () {
    it('goes back to PENDING with what was sent, from approved or rejected alike', function (CompanyStatus $from) {
        $company = companyIn($from);
        $company->applyAgain(companyTestDetails('Al Noor Trading Co.'), CarbonImmutable::now());

        expect($company->status())->toBe(CompanyStatus::Pending)
            ->and($company->details()->name->value)->toBe('Al Noor Trading Co.')
            // Reapplying never restores ordering in the meantime (handoff §8.2).
            ->and($company->mayOrder())->toBeFalse()
            ->and($company->statusReason())->toBeNull()
            ->and($company->pullChanges())->toBe(['name', 'status']);
    })->with([CompanyStatus::Approved, CompanyStatus::Rejected]);

    it('refuses a suspended company: a rename must not undo a suspension', function () {
        expect(fn () => companyIn(CompanyStatus::Suspended)->applyAgain(companyTestDetails('Another name'), CarbonImmutable::now()))
            ->toThrow(CompanySuspended::class);
    });

    it('refuses while one application is already waiting', function () {
        expect(fn () => companyIn(CompanyStatus::Pending)->applyAgain(companyTestDetails(), CarbonImmutable::now()))
            ->toThrow(InvalidCompanyStatus::class);
    });
});

describe('what changes without an application', function () {
    it('moves the address in any status, suspended included, and leaves the status alone', function (CompanyStatus $status) {
        $company = companyIn($status);
        $company->moveTo(CompanyAddress::of("Olaya Street\nRiyadh"));

        expect($company->details()->address->value)->toBe("Olaya Street\nRiyadh")
            ->and($company->status())->toBe($status)
            ->and($company->pullChanges())->toBe(['address']);
    })->with([CompanyStatus::Pending, CompanyStatus::Approved, CompanyStatus::Rejected, CompanyStatus::Suspended]);

    it('lets staff correct the type without sending the company back to PENDING', function () {
        $company = companyIn(CompanyStatus::Approved);
        $company->correctType(CompanyTypeChoice::other('Cooperative society'));

        expect($company->details()->type->other)->toBe('Cooperative society')
            ->and($company->status())->toBe(CompanyStatus::Approved)
            ->and($company->pullChanges())->toBe(['company_type']);
    });

    it('records nothing when nothing changed', function () {
        $company = companyIn(CompanyStatus::Approved);
        $company->moveTo(CompanyAddress::of("King Fahd Road\nRiyadh"));
        $company->correctType(CompanyTypeChoice::listed('01j8z3k4m5n6p7q8r9s0t1v2w1'));

        expect($company->pullChanges())->toBe([]);
    });
});
