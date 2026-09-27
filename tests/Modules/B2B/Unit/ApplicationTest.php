<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Exception\MissingRequiredDocument;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\TypeName;

/*
| An application's life (b2b.md §1.2, §4.2, amendments 2 and 3).
*/

const APPLICATION_TEST_COMPANY = '01j8z3k4m5n6p7q8r9s0t1v2c1';

const APPLICATION_TEST_LLC = '01j8z3k4m5n6p7q8r9s0t1v2t1';

const APPLICATION_TEST_VAT = '01j8z3k4m5n6p7q8r9s0t1v2d1';

const APPLICATION_TEST_CR = '01j8z3k4m5n6p7q8r9s0t1v2d2';

/**
 * @return list<CompanyType>
 */
function applicationTestCompanyTypes(): array
{
    return [CompanyType::add(APPLICATION_TEST_LLC, TypeName::of('شركة ذات مسؤولية محدودة', 'Limited Liability Company'), 1)];
}

/**
 * The two required document types, and an optional one.
 *
 * @return list<DocumentType>
 */
function applicationTestDocumentTypes(): array
{
    return [
        DocumentType::add(APPLICATION_TEST_VAT, TypeName::of('شهادة ضريبة القيمة المضافة', 'VAT certificate'), 1, isRequired: true),
        DocumentType::add(APPLICATION_TEST_CR, TypeName::of('شهادة السجل التجاري', 'Commercial registration certificate'), 2, isRequired: true),
        DocumentType::add('01j8z3k4m5n6p7q8r9s0t1v2d3', TypeName::of('خطاب', 'Letter'), 3, isRequired: false),
    ];
}

/**
 * A draft with everything filled in and both required files attached, ready to send.
 */
function completeDraft(?CompanyTypeChoice $type = null, ?string $companyId = null): Application
{
    $draft = Application::draft('01j8z3k4m5n6p7q8r9s0t1v2a1', '01j8z3k4m5n6p7q8r9s0t1v2u1', $companyId);
    $draft->describe(
        CompanyName::of('Al Noor Trading'),
        $type ?? CompanyTypeChoice::listed(APPLICATION_TEST_LLC),
        RegistrationNumber::of('cr_number', '1010123456'),
        RegistrationNumber::of('tax_number', '300123456700003'),
        CompanyAddress::of("King Fahd Road\nRiyadh"),
        null,
    );
    $draft->attach(APPLICATION_TEST_VAT, '01j8z3k4m5n6p7q8r9s0t1v2m1', CarbonImmutable::now());
    $draft->attach(APPLICATION_TEST_CR, '01j8z3k4m5n6p7q8r9s0t1v2m2', CarbonImmutable::now());

    return $draft;
}

function sentApplication(): Application
{
    $application = completeDraft();
    $application->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), CarbonImmutable::now());

    return $application;
}

describe('a draft', function () {
    it('starts empty and open, with no company behind it yet', function () {
        $draft = Application::draft('01j8z3k4m5n6p7q8r9s0t1v2a1', '01j8z3k4m5n6p7q8r9s0t1v2u1', null);

        expect($draft->state())->toBe(ApplicationState::Draft)
            ->and($draft->isOpen())->toBeTrue()
            ->and($draft->companyId())->toBeNull()
            ->and($draft->name())->toBeNull();
    });

    it('holds one file per document type: a second upload replaces the first and says which', function () {
        $draft = Application::draft('01j8z3k4m5n6p7q8r9s0t1v2a1', '01j8z3k4m5n6p7q8r9s0t1v2u1', null);

        expect($draft->attach(APPLICATION_TEST_VAT, '01j8z3k4m5n6p7q8r9s0t1v2m1', CarbonImmutable::now()))->toBeNull()
            ->and($draft->attach(APPLICATION_TEST_VAT, '01j8z3k4m5n6p7q8r9s0t1v2m9', CarbonImmutable::now()))->toBe('01j8z3k4m5n6p7q8r9s0t1v2m1')
            ->and($draft->documents())->toHaveCount(1)
            ->and($draft->documents()[APPLICATION_TEST_VAT]->mediaId)->toBe('01j8z3k4m5n6p7q8r9s0t1v2m9')
            ->and($draft->detach(APPLICATION_TEST_VAT))->toBe('01j8z3k4m5n6p7q8r9s0t1v2m9')
            ->and($draft->detach(APPLICATION_TEST_VAT))->toBeNull()
            ->and($draft->documents())->toBe([]);
    });

    it('may be thrown away while it is a draft', function () {
        completeDraft()->ensureDiscardable();

        expect(true)->toBeTrue();
    });
});

describe('sending it', function () {
    it('sends a complete draft, which then belongs to its company and is the staff\'s', function () {
        $application = completeDraft();
        $details = $application->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), CarbonImmutable::parse('2026-09-27 12:00'));

        expect($application->state())->toBe(ApplicationState::Submitted)
            ->and($application->companyId())->toBe(APPLICATION_TEST_COMPANY)
            ->and($application->submittedAt()?->format('H:i'))->toBe('12:00')
            ->and($details->name->value)->toBe('Al Noor Trading')
            ->and($details->type->typeId)->toBe(APPLICATION_TEST_LLC);
    });

    it('refuses a draft with anything missing, and says which', function (string $missing) {
        $draft = completeDraft();
        $draft->describe(
            $missing === 'name' ? null : CompanyName::of('Al Noor Trading'),
            $missing === 'company_type' ? null : CompanyTypeChoice::listed(APPLICATION_TEST_LLC),
            $missing === 'cr_number' ? null : RegistrationNumber::of('cr_number', '1010123456'),
            $missing === 'tax_number' ? null : RegistrationNumber::of('tax_number', '300123456700003'),
            $missing === 'address' ? null : CompanyAddress::of('Riyadh'),
            null,
        );

        expect(fn () => $draft->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), CarbonImmutable::now()))
            ->toThrow(InvalidCompanyAttribute::class, "Invalid {$missing}: required")
            ->and($draft->state())->toBe(ApplicationState::Draft);
    })->with(['name', 'company_type', 'cr_number', 'tax_number', 'address']);

    it('refuses a type staff have stopped offering since the draft chose it (owner, 2026-09-27)', function () {
        expect(fn () => completeDraft()->submit(APPLICATION_TEST_COMPANY, [], applicationTestDocumentTypes(), CarbonImmutable::now()))
            ->toThrow(CompanyTypeInactive::class);
    });

    it('takes "Other" whatever types are offered: it is not a row staff can switch off', function () {
        $application = completeDraft(CompanyTypeChoice::other('Cooperative society'));

        expect($application->submit(APPLICATION_TEST_COMPANY, [], applicationTestDocumentTypes(), CarbonImmutable::now())->type->other)
            ->toBe('Cooperative society');
    });

    it('refuses a draft missing a required file, and names the type', function () {
        $draft = completeDraft();
        $draft->detach(APPLICATION_TEST_CR);

        expect(fn () => $draft->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), CarbonImmutable::now()))
            ->toThrow(MissingRequiredDocument::class, APPLICATION_TEST_CR);
    });

    it('asks nobody for an optional type, or for a required one staff have stopped offering', function () {
        $types = applicationTestDocumentTypes();
        $types[1]->deactivate();

        $draft = completeDraft();
        $draft->detach(APPLICATION_TEST_CR);
        $draft->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), $types, CarbonImmutable::now());

        expect($draft->state())->toBe(ApplicationState::Submitted);
    });

    it('never sends a company\'s draft as another company\'s', function () {
        // Complete, so nothing else refuses it first.
        $draft = completeDraft(companyId: APPLICATION_TEST_COMPANY);

        expect(fn () => $draft->submit('01j8z3k4m5n6p7q8r9s0t1v2c9', applicationTestCompanyTypes(), applicationTestDocumentTypes(), CarbonImmutable::now()))
            ->toThrow(LogicException::class)
            ->and($draft->state())->toBe(ApplicationState::Draft);
    });
});

describe('once it is sent', function () {
    it('can no longer be changed, or thrown away, by the customer', function (Closure $change) {
        expect(fn () => $change(sentApplication()))->toThrow(ApplicationNotEditable::class);
    })->with([
        'its details' => [fn (Application $application) => $application->describe(null, null, null, null, null, null)],
        'a file added' => [fn (Application $application) => $application->attach(APPLICATION_TEST_VAT, '01j8z3k4m5n6p7q8r9s0t1v2m7', CarbonImmutable::now())],
        'a file removed' => [fn (Application $application) => $application->detach(APPLICATION_TEST_VAT)],
        'thrown away' => [fn (Application $application) => $application->ensureDiscardable()],
        'sent again' => [fn (Application $application) => $application->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), CarbonImmutable::now())],
    ]);

    it('is approved with the staff member\'s note, if any, and is then closed for good', function () {
        $application = sentApplication();
        $application->approve('01j8z3k4m5n6p7q8r9s0t1v2s1', Remark::of('note', 'Welcome aboard.'), CarbonImmutable::now());

        expect($application->state())->toBe(ApplicationState::Approved)
            ->and($application->isOpen())->toBeFalse()
            ->and($application->decidedBy())->toBe('01j8z3k4m5n6p7q8r9s0t1v2s1')
            ->and($application->decisionReason()?->value)->toBe('Welcome aboard.')
            ->and(fn () => $application->reject('01j8z3k4m5n6p7q8r9s0t1v2s1', Remark::of('reason', 'Changed my mind.'), CarbonImmutable::now()))
            ->toThrow(InvalidCompanyStatus::class);
    });

    it('is approved without a note: the approval needs none (amendment 1)', function () {
        $application = sentApplication();
        $application->approve('01j8z3k4m5n6p7q8r9s0t1v2s1', null, CarbonImmutable::now());

        expect($application->decisionReason())->toBeNull();
    });

    it('is rejected with its reason', function () {
        $application = sentApplication();
        $application->reject('01j8z3k4m5n6p7q8r9s0t1v2s1', Remark::of('reason', 'The VAT certificate has expired.'), CarbonImmutable::now());

        expect($application->state())->toBe(ApplicationState::Rejected)
            ->and($application->decisionReason()?->value)->toBe('The VAT certificate has expired.');
    });

    it('decides only a sent application, never a draft', function () {
        expect(fn () => completeDraft()->approve('01j8z3k4m5n6p7q8r9s0t1v2s1', null, CarbonImmutable::now()))
            ->toThrow(InvalidCompanyStatus::class);
    });
});
