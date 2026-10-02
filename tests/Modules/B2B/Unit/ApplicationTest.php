<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\DocumentNoLongerAccepted;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Exception\MissingRequiredDocument;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationReference;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\B2B\Domain\ValueObject\TypeName;

/*
| An application's life (b2b.md §1.2, §4.2, amendments 2, 3, 5 and 6).
*/

const APPLICATION_TEST_STORE = '01j8z3k4m5n6p7q8r9s0t1v2e1';

const APPLICATION_TEST_COMPANY = '01j8z3k4m5n6p7q8r9s0t1v2c1';

const APPLICATION_TEST_LLC = '01j8z3k4m5n6p7q8r9s0t1v2t1';

const APPLICATION_TEST_VAT = '01j8z3k4m5n6p7q8r9s0t1v2d1';

const APPLICATION_TEST_CR = '01j8z3k4m5n6p7q8r9s0t1v2d2';

const APPLICATION_TEST_LETTER = '01j8z3k4m5n6p7q8r9s0t1v2d3';

/**
 * The home store's company types: one, active.
 *
 * @return list<CompanyType>
 */
function applicationTestCompanyTypes(): array
{
    return [CompanyType::add(APPLICATION_TEST_LLC, APPLICATION_TEST_STORE, TypeName::of('شركة ذات مسؤولية محدودة', 'Limited Liability Company'), 1)];
}

/**
 * The home store's document types: two required, and an optional one.
 *
 * @return list<DocumentType>
 */
function applicationTestDocumentTypes(): array
{
    return [
        DocumentType::add(APPLICATION_TEST_VAT, APPLICATION_TEST_STORE, TypeName::of('شهادة ضريبة القيمة المضافة', 'VAT certificate'), 1, isRequired: true),
        DocumentType::add(APPLICATION_TEST_CR, APPLICATION_TEST_STORE, TypeName::of('شهادة السجل التجاري', 'Commercial registration certificate'), 2, isRequired: true),
        DocumentType::add(APPLICATION_TEST_LETTER, APPLICATION_TEST_STORE, TypeName::of('خطاب', 'Letter'), 3, isRequired: false),
    ];
}

/**
 * A draft with everything filled in and both required files attached, ready to send.
 */
function completeDraft(?CompanyTypeChoice $type = null, ?string $companyId = null): Application
{
    $draft = Application::draft('01j8z3k4m5n6p7q8r9s0t1v2a1', '01j8z3k4m5n6p7q8r9s0t1v2u1', $companyId, '01j8z3k4m5n6p7q8r9s0t1v2s5');
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
    $application->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), null, CarbonImmutable::now(), ApplicationReference::of(2026, 1));

    return $application;
}

/**
 * Sends a draft against the test store's lists, as they are or as given.
 *
 * @param  list<CompanyType>|null  $companyTypes
 * @param  list<DocumentType>|null  $documentTypes
 */
function applicationTestSend(Application $draft, ?array $companyTypes = null, ?array $documentTypes = null): void
{
    $draft->submit(APPLICATION_TEST_COMPANY, $companyTypes ?? applicationTestCompanyTypes(), $documentTypes ?? applicationTestDocumentTypes(), null, CarbonImmutable::now(), ApplicationReference::of(2026, 1));
}

describe('a draft', function () {
    it('starts empty and open, with no company behind it yet', function () {
        $draft = Application::draft('01j8z3k4m5n6p7q8r9s0t1v2a1', '01j8z3k4m5n6p7q8r9s0t1v2u1', null, '01j8z3k4m5n6p7q8r9s0t1v2s5');

        expect($draft->state())->toBe(ApplicationState::Draft)
            ->and($draft->isOpen())->toBeTrue()
            ->and($draft->companyId())->toBeNull()
            ->and($draft->name())->toBeNull()
            ->and($draft->flags())->toBe([])
            ->and($draft->requests())->toBe([])
            ->and($draft->answers())->toBe([]);
    });

    it('holds one file per document type: a second upload replaces the first and says which', function () {
        $draft = Application::draft('01j8z3k4m5n6p7q8r9s0t1v2a1', '01j8z3k4m5n6p7q8r9s0t1v2u1', null, '01j8z3k4m5n6p7q8r9s0t1v2s5');

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
        $details = $application->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), null, CarbonImmutable::parse('2026-09-27 12:00'), ApplicationReference::of(2026, 1));

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

        expect(fn () => applicationTestSend($draft))
            ->toThrow(InvalidCompanyAttribute::class, "Invalid {$missing}: required")
            ->and($draft->state())->toBe(ApplicationState::Draft);
    })->with(['name', 'company_type', 'cr_number', 'tax_number', 'address']);

    it('refuses a listed type staff have stopped offering since the draft chose it (owner, 2026-09-27)', function () {
        $types = applicationTestCompanyTypes();
        $types[0]->deactivate(InactiveTypeDisplay::Greyed);
        $draft = completeDraft();

        expect(fn () => applicationTestSend($draft, companyTypes: $types))->toThrow(CompanyTypeInactive::class)
            ->and($draft->state())->toBe(ApplicationState::Draft);
    });

    it('refuses a listed type that is not one of the home store\'s — another store\'s — as not valid, not as inactive (amendment 6(d))', function () {
        // The type exists and is active, but in another store's list.
        $draft = completeDraft(CompanyTypeChoice::listed('01j8z3k4m5n6p7q8r9s0t1v2t9'));
        $error = null;

        try {
            applicationTestSend($draft);
        } catch (InvalidCompanyAttribute $caught) {
            $error = $caught;
        }

        expect($error?->attribute)->toBe('company_type')
            ->and($draft->state())->toBe(ApplicationState::Draft);
    });

    it('takes "Other" whatever types are offered: it is not a row staff can switch off', function () {
        $application = completeDraft(CompanyTypeChoice::other('Cooperative society'));

        expect($application->submit(APPLICATION_TEST_COMPANY, [], applicationTestDocumentTypes(), null, CarbonImmutable::now(), ApplicationReference::of(2026, 1))->type->other)
            ->toBe('Cooperative society');
    });

    it('refuses a draft missing a required file, and names the type', function () {
        $draft = completeDraft();
        $draft->detach(APPLICATION_TEST_CR);

        expect(fn () => applicationTestSend($draft))
            ->toThrow(MissingRequiredDocument::class, APPLICATION_TEST_CR);
    });

    it('asks nobody for an optional type, or for a required one staff have stopped offering', function () {
        $types = applicationTestDocumentTypes();
        $types[1]->deactivate(InactiveTypeDisplay::Hidden);

        $draft = completeDraft();
        $draft->detach(APPLICATION_TEST_CR);
        applicationTestSend($draft, documentTypes: $types);

        expect($draft->state())->toBe(ApplicationState::Submitted);
    });

    it('never sends a company\'s draft as another company\'s', function () {
        // Complete, so nothing else refuses it first.
        $draft = completeDraft(companyId: APPLICATION_TEST_COMPANY);

        expect(fn () => $draft->submit('01j8z3k4m5n6p7q8r9s0t1v2c9', applicationTestCompanyTypes(), applicationTestDocumentTypes(), null, CarbonImmutable::now(), ApplicationReference::of(2026, 1)))
            ->toThrow(LogicException::class)
            ->and($draft->state())->toBe(ApplicationState::Draft);
    });
});

describe('a draft never sends anything deactivated (amendment 5)', function () {
    it('refuses a file under a document type deactivated since, naming it, and sends once the file is out', function (string $typeId, int $index) {
        $types = applicationTestDocumentTypes();
        $types[$index]->deactivate(InactiveTypeDisplay::Greyed);
        $draft = completeDraft();
        $draft->attach(APPLICATION_TEST_LETTER, '01j8z3k4m5n6p7q8r9s0t1v2m3', CarbonImmutable::now());

        expect(fn () => applicationTestSend($draft, documentTypes: $types))
            ->toThrow(DocumentNoLongerAccepted::class, $typeId)
            ->and($draft->state())->toBe(ApplicationState::Draft);

        $draft->detach($typeId);
        applicationTestSend($draft, documentTypes: $types);

        expect($draft->state())->toBe(ApplicationState::Submitted);
    })->with([
        'a required type' => [APPLICATION_TEST_CR, 1],
        'an optional type' => [APPLICATION_TEST_LETTER, 2],
    ]);

    it('treats a file under a type missing from the home store\'s list as a caller\'s bug', function () {
        $draft = completeDraft();
        $draft->attach('01j8z3k4m5n6p7q8r9s0t1v2d9', '01j8z3k4m5n6p7q8r9s0t1v2m3', CarbonImmutable::now());

        expect(fn () => applicationTestSend($draft))->toThrow(LogicException::class)
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
        'sent again' => [fn (Application $application) => $application->submit(APPLICATION_TEST_COMPANY, applicationTestCompanyTypes(), applicationTestDocumentTypes(), null, CarbonImmutable::now(), ApplicationReference::of(2026, 1))],
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

describe('when the account is anonymized (amendment 12(a))', function () {
    it('gives up its values, its note and its papers, and keeps its state, type, decision, flags and requests', function () {
        $application = Application::draft('01j8z3k4m5n6p7q8r9s0t1v2a2', '01j8z3k4m5n6p7q8r9s0t1v2u1', null, '01j8z3k4m5n6p7q8r9s0t1v2s5');
        $application->describe(
            CompanyName::of('Al Noor Trading'),
            CompanyTypeChoice::listed(APPLICATION_TEST_LLC),
            RegistrationNumber::of('cr_number', '1010123456'),
            RegistrationNumber::of('tax_number', '300123456700003'),
            CompanyAddress::of("King Fahd Road\nRiyadh"),
            Remark::of('note', 'Please call the owner before noon.'),
        );
        $application->attach(APPLICATION_TEST_VAT, '01j8z3k4m5n6p7q8r9s0t1v2m1', CarbonImmutable::now());
        $application->attach(APPLICATION_TEST_CR, '01j8z3k4m5n6p7q8r9s0t1v2m2', CarbonImmutable::now());
        applicationTestSend($application);
        $flags = [ApplicationFlag::field(FlaggedField::CrNumber), ApplicationFlag::document(APPLICATION_TEST_VAT)];
        $requests = [ApplicationRequest::add('01j8z3k4m5n6p7q8r9s0t1v2r1', RequestKind::File, 'A bank letter', 0)];
        $application->reject('01j8z3k4m5n6p7q8r9s0t1v2s1', Remark::of('reason', 'The VAT certificate has expired.'), CarbonImmutable::now(), $flags, $requests);
        $application->pullChanges();

        $released = $application->anonymize();

        expect($released)->toBe(['01j8z3k4m5n6p7q8r9s0t1v2m1', '01j8z3k4m5n6p7q8r9s0t1v2m2'])
            ->and([$application->name()?->value, $application->crNumber()?->value, $application->taxNumber()?->value, $application->address()?->value])
            ->toBe(['Deleted company', 'Deleted', 'Deleted', 'Deleted'])
            ->and($application->note())->toBeNull()
            ->and($application->documents())->toBe([])
            ->and($application->answers())->toBe([])
            ->and($application->state())->toBe(ApplicationState::Rejected)
            ->and($application->type()?->typeId)->toBe(APPLICATION_TEST_LLC)
            ->and($application->decidedBy())->toBe('01j8z3k4m5n6p7q8r9s0t1v2s1')
            ->and($application->decisionReason()?->value)->toBe('The VAT certificate has expired.')
            ->and($application->submittedAt())->not->toBeNull()
            ->and($application->flags())->toHaveCount(2)
            ->and($application->requests())->toHaveCount(1)
            ->and($application->pullChanges())->toBe(['details', 'documents']);
    });

    it('lets go of nothing, and changes nothing, a second time', function () {
        $application = sentApplication();
        $application->anonymize();
        $application->pullChanges();

        expect($application->anonymize())->toBe([])
            ->and($application->pullChanges())->toBe([]);
    });

    it('is never asked of a draft, which is deleted whole instead', function () {
        expect(fn () => completeDraft()->anonymize())->toThrow(LogicException::class);
    });
});

describe('when the account of a company that sent "Other" is anonymized (amendment 13(a))', function () {
    it('gives up the words for its type too, and stays "Other"; a second time changes nothing', function () {
        $application = completeDraft(CompanyTypeChoice::other('Cooperative of one'));
        applicationTestSend($application);
        $application->pullChanges();

        $application->anonymize();

        expect($application->type()?->isOther())->toBeTrue()
            ->and($application->type()?->other)->toBe('Deleted')
            ->and($application->pullChanges())->toBe(['details', 'documents']);

        $application->anonymize();

        expect($application->pullChanges())->toBe([]);
    });
});
