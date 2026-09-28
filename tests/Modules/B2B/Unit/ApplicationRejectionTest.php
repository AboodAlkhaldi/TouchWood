<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\B2B\Domain\Exception\AnswerKindMismatch;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\DocumentNoLongerAccepted;
use Modules\B2B\Domain\Exception\FlaggedItemNotReplaced;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Exception\RequestNotAnswered;
use Modules\B2B\Domain\Exception\RequestNotFound;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\AttachedDocument;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestAnswer;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\B2B\Domain\ValueObject\TypeName;

/*
| What a rejection says to fix and what to add, and the next draft meeting it (b2b.md §1.2, §3.1,
| amendments 4, 5 and 6): flags, requests, answers, and the order in which sending is refused.
*/

const REJECTION_TEST_STORE = '01j8z3k4m5n6p7q8r9s0t1v3e1';

const REJECTION_TEST_COMPANY = '01j8z3k4m5n6p7q8r9s0t1v3c1';

const REJECTION_TEST_OTHER_COMPANY = '01j8z3k4m5n6p7q8r9s0t1v3c2';

const REJECTION_TEST_CUSTOMER = '01j8z3k4m5n6p7q8r9s0t1v3k1';

const REJECTION_TEST_STAFF = '01j8z3k4m5n6p7q8r9s0t1v3s1';

const REJECTION_TEST_FIRST = '01j8z3k4m5n6p7q8r9s0t1v3a1';

const REJECTION_TEST_NEXT = '01j8z3k4m5n6p7q8r9s0t1v3a2';

const REJECTION_TEST_LLC = '01j8z3k4m5n6p7q8r9s0t1v3t1';

const REJECTION_TEST_JSC = '01j8z3k4m5n6p7q8r9s0t1v3t2';

const REJECTION_TEST_VAT = '01j8z3k4m5n6p7q8r9s0t1v3d1';

const REJECTION_TEST_CR = '01j8z3k4m5n6p7q8r9s0t1v3d2';

const REJECTION_TEST_LETTER = '01j8z3k4m5n6p7q8r9s0t1v3d3';

const REJECTION_TEST_PERMIT = '01j8z3k4m5n6p7q8r9s0t1v3d4';

const REJECTION_TEST_VAT_FILE = '01j8z3k4m5n6p7q8r9s0t1v3m1';

const REJECTION_TEST_CR_FILE = '01j8z3k4m5n6p7q8r9s0t1v3m2';

const REJECTION_TEST_LETTER_FILE = '01j8z3k4m5n6p7q8r9s0t1v3m3';

const REJECTION_TEST_NEW_FILE = '01j8z3k4m5n6p7q8r9s0t1v3m5';

const REJECTION_TEST_NEWER_FILE = '01j8z3k4m5n6p7q8r9s0t1v3m6';

const REJECTION_TEST_TEXT_REQUEST = '01j8z3k4m5n6p7q8r9s0t1v3r1';

const REJECTION_TEST_FILE_REQUEST = '01j8z3k4m5n6p7q8r9s0t1v3r2';

/**
 * The home store's company types: two, active — so a draft can move from one to the other.
 *
 * @return list<CompanyType>
 */
function rejectionTestCompanyTypes(): array
{
    return [
        CompanyType::add(REJECTION_TEST_LLC, REJECTION_TEST_STORE, TypeName::of('شركة ذات مسؤولية محدودة', 'Limited Liability Company'), 1),
        CompanyType::add(REJECTION_TEST_JSC, REJECTION_TEST_STORE, TypeName::of('شركة مساهمة', 'Joint Stock Company'), 2),
    ];
}

/**
 * The home store's document types: two required, two optional.
 *
 * @return list<DocumentType>
 */
function rejectionTestDocumentTypes(): array
{
    return [
        DocumentType::add(REJECTION_TEST_VAT, REJECTION_TEST_STORE, TypeName::of('شهادة ضريبة القيمة المضافة', 'VAT certificate'), 1, isRequired: true),
        DocumentType::add(REJECTION_TEST_CR, REJECTION_TEST_STORE, TypeName::of('شهادة السجل التجاري', 'Commercial registration certificate'), 2, isRequired: true),
        DocumentType::add(REJECTION_TEST_LETTER, REJECTION_TEST_STORE, TypeName::of('خطاب تفويض', 'Letter of authorisation'), 3, isRequired: false),
        DocumentType::add(REJECTION_TEST_PERMIT, REJECTION_TEST_STORE, TypeName::of('رخصة البلدية', 'Municipal permit'), 4, isRequired: false),
    ];
}

/**
 * Fills in the five values as the first application sent them, or with the ones given.
 *
 * @param  array<string, string>  $values  by FlaggedField value
 * @param  CompanyTypeChoice|null  $type  a type picked from the list, or any choice, in place of
 *                                        the "Other" words in $values
 */
function rejectionTestFillIn(Application $application, array $values = [], ?CompanyTypeChoice $type = null): void
{
    $application->describe(
        CompanyName::of($values['name'] ?? 'Al Noor Trading'),
        $type ?? CompanyTypeChoice::other($values['company_type'] ?? 'Trading house'),
        RegistrationNumber::of('cr_number', $values['cr_number'] ?? 'CR-1010123456'),
        RegistrationNumber::of('tax_number', $values['tax_number'] ?? 'VAT-300123456700003'),
        CompanyAddress::of($values['address'] ?? "12 Industrial Road\nBlock 4"),
        null,
    );
}

/**
 * The company's first application, sent with three files and waiting for a decision.
 */
function rejectionTestSent(string $companyId = REJECTION_TEST_COMPANY, ?CompanyTypeChoice $type = null): Application
{
    $application = Application::draft(REJECTION_TEST_FIRST, REJECTION_TEST_CUSTOMER, null);
    rejectionTestFillIn($application, [], $type);
    $application->attach(REJECTION_TEST_VAT, REJECTION_TEST_VAT_FILE, CarbonImmutable::now());
    $application->attach(REJECTION_TEST_CR, REJECTION_TEST_CR_FILE, CarbonImmutable::now());
    $application->attach(REJECTION_TEST_LETTER, REJECTION_TEST_LETTER_FILE, CarbonImmutable::now());
    $application->submit($companyId, rejectionTestCompanyTypes(), rejectionTestDocumentTypes(), null, CarbonImmutable::now());

    return $application;
}

/**
 * The first application, rejected with these flags and requests.
 *
 * @param  list<ApplicationFlag>  $flags
 * @param  list<ApplicationRequest>  $requests
 */
function rejectionTestRejected(array $flags = [], array $requests = [], string $companyId = REJECTION_TEST_COMPANY, ?CompanyTypeChoice $type = null): Application
{
    $application = rejectionTestSent($companyId, $type);
    $application->reject(REJECTION_TEST_STAFF, Remark::of('reason', 'The CR number does not match the certificate.'), CarbonImmutable::now(), $flags, $requests);

    return $application;
}

/**
 * A decided application read back as the database may hold it — flags and requests on whatever
 * state it has. Only the code keeps them to rejections (amendment 6(c)), so this is how a test
 * shows that the state alone decides whether they apply.
 *
 * @param  list<ApplicationFlag>  $flags
 * @param  list<ApplicationRequest>  $requests
 */
function rejectionTestReconstituted(ApplicationState $state, array $flags, array $requests): Application
{
    $at = CarbonImmutable::now();

    return Application::reconstitute(
        REJECTION_TEST_FIRST,
        REJECTION_TEST_CUSTOMER,
        REJECTION_TEST_COMPANY,
        $state,
        CompanyName::of('Al Noor Trading'),
        CompanyTypeChoice::other('Trading house'),
        RegistrationNumber::of('cr_number', 'CR-1010123456'),
        RegistrationNumber::of('tax_number', 'VAT-300123456700003'),
        CompanyAddress::of("12 Industrial Road\nBlock 4"),
        null,
        [
            REJECTION_TEST_VAT => new AttachedDocument(REJECTION_TEST_VAT, REJECTION_TEST_VAT_FILE, $at),
            REJECTION_TEST_CR => new AttachedDocument(REJECTION_TEST_CR, REJECTION_TEST_CR_FILE, $at),
            REJECTION_TEST_LETTER => new AttachedDocument(REJECTION_TEST_LETTER, REJECTION_TEST_LETTER_FILE, $at),
        ],
        $at,
        $at,
        REJECTION_TEST_STAFF,
        null,
        $flags,
        $requests,
        [],
    );
}

/**
 * The company's next draft: the details it holds now and the files of the last application sent
 * (amendment 4(b)).
 */
function rejectionTestNextDraft(): Application
{
    $draft = Application::draft(REJECTION_TEST_NEXT, REJECTION_TEST_CUSTOMER, REJECTION_TEST_COMPANY);
    rejectionTestFillIn($draft);
    $draft->attach(REJECTION_TEST_VAT, REJECTION_TEST_VAT_FILE, CarbonImmutable::now());
    $draft->attach(REJECTION_TEST_CR, REJECTION_TEST_CR_FILE, CarbonImmutable::now());
    $draft->attach(REJECTION_TEST_LETTER, REJECTION_TEST_LETTER_FILE, CarbonImmutable::now());

    return $draft;
}

/**
 * A text request, then a file request.
 *
 * @return list<ApplicationRequest>
 */
function rejectionTestRequests(): array
{
    return [
        ApplicationRequest::add(REJECTION_TEST_TEXT_REQUEST, RequestKind::Text, 'Your trade name as registered', 1),
        ApplicationRequest::add(REJECTION_TEST_FILE_REQUEST, RequestKind::File, 'A bank letter confirming the account', 2),
    ];
}

function rejectionTestText(string $requestId = REJECTION_TEST_TEXT_REQUEST, string $text = 'Al Noor Trading Establishment'): RequestAnswer
{
    return RequestAnswer::text($requestId, Remark::of('answer', $text));
}

/**
 * Sends the draft against the home store's lists, as they are or as given.
 *
 * @param  list<DocumentType>|null  $documentTypes
 */
function rejectionTestSend(Application $draft, ?Application $lastSent, ?array $documentTypes = null): void
{
    $draft->submit(REJECTION_TEST_COMPANY, rejectionTestCompanyTypes(), $documentTypes ?? rejectionTestDocumentTypes(), $lastSent, CarbonImmutable::now());
}

/**
 * @param  list<ApplicationFlag>  $flags
 * @return list<string>
 */
function rejectionTestKeys(array $flags): array
{
    return array_map(static fn (ApplicationFlag $flag): string => $flag->key(), $flags);
}

describe('a rejection that says what to fix and what to add (amendment 4)', function () {
    it('keeps a rejection\'s flags and requests, and no other application carries any', function () {
        $rejected = rejectionTestRejected([ApplicationFlag::field(FlaggedField::Name), ApplicationFlag::document(REJECTION_TEST_CR)], rejectionTestRequests());
        $approved = rejectionTestSent();
        $approved->approve(REJECTION_TEST_STAFF, null, CarbonImmutable::now());

        expect(rejectionTestKeys($rejected->flags()))->toBe(['field:name', 'document:'.REJECTION_TEST_CR])
            ->and(array_map(static fn (ApplicationRequest $request): string => $request->id, $rejected->requests()))->toBe([REJECTION_TEST_TEXT_REQUEST, REJECTION_TEST_FILE_REQUEST])
            ->and($rejected->requests()[1]->kind)->toBe(RequestKind::File)
            ->and($rejected->requests()[1]->label)->toBe('A bank letter confirming the account');

        foreach ([$approved, rejectionTestNextDraft(), rejectionTestSent()] as $other) {
            expect($other->flags())->toBe([])
                ->and($other->requests())->toBe([]);
        }
    });

    it('lists the requests by position, then by id', function () {
        $rejected = rejectionTestRejected([], [
            ApplicationRequest::add('01j8z3k4m5n6p7q8r9s0t1v3r7', RequestKind::Text, 'Third', 5),
            ApplicationRequest::add('01j8z3k4m5n6p7q8r9s0t1v3r6', RequestKind::File, 'Second', 2),
            ApplicationRequest::add('01j8z3k4m5n6p7q8r9s0t1v3r5', RequestKind::Text, 'First', 2),
        ]);

        expect(array_map(static fn (ApplicationRequest $request): string => $request->label, $rejected->requests()))->toBe(['First', 'Second', 'Third']);
    });

    it('rejects nothing but a sent application, and a refused rejection keeps no flag or request', function () {
        $draft = rejectionTestNextDraft();

        // Both flags name what the draft holds, so only the state can refuse.
        expect(fn () => $draft->reject(REJECTION_TEST_STAFF, Remark::of('reason', 'Wrong.'), CarbonImmutable::now(), [ApplicationFlag::field(FlaggedField::Name), ApplicationFlag::document(REJECTION_TEST_CR)], rejectionTestRequests()))
            ->toThrow(InvalidCompanyStatus::class)
            ->and($draft->state())->toBe(ApplicationState::Draft)
            ->and($draft->flags())->toBe([])
            ->and($draft->requests())->toBe([]);
    });

    it('flags a document only when the application sent a file under its type', function () {
        $application = rejectionTestSent();
        $error = null;

        try {
            $application->reject(REJECTION_TEST_STAFF, Remark::of('reason', 'Wrong.'), CarbonImmutable::now(), [ApplicationFlag::document(REJECTION_TEST_PERMIT)]);
        } catch (InvalidCompanyAttribute $caught) {
            $error = $caught;
        }

        expect($error?->attribute)->toBe('flags')
            ->and($application->state())->toBe(ApplicationState::Submitted)
            ->and($application->flags())->toBe([]);
    });

    it('flags any of the five fields', function () {
        $application = rejectionTestSent();

        $application->reject(REJECTION_TEST_STAFF, Remark::of('reason', 'Wrong.'), CarbonImmutable::now(), array_map(ApplicationFlag::field(...), FlaggedField::cases()));

        expect(rejectionTestKeys($application->flags()))->toBe(['field:name', 'field:company_type', 'field:cr_number', 'field:tax_number', 'field:address']);
    });

    it('keeps a flag given twice once, and matches a document flag given in capitals to its file', function () {
        $application = rejectionTestSent();

        $application->reject(REJECTION_TEST_STAFF, Remark::of('reason', 'Wrong.'), CarbonImmutable::now(), [
            ApplicationFlag::field(FlaggedField::Name),
            ApplicationFlag::document(strtoupper(REJECTION_TEST_CR)),
            ApplicationFlag::field(FlaggedField::Name),
            ApplicationFlag::document(REJECTION_TEST_CR),
        ]);

        expect(rejectionTestKeys($application->flags()))->toBe(['field:name', 'document:'.REJECTION_TEST_CR]);
    });

    it('refuses a request the company could not answer', function (Closure $request, string $attribute) {
        $error = null;

        try {
            $request();
        } catch (InvalidCompanyAttribute $caught) {
            $error = $caught;
        }

        expect($error?->attribute)->toBe($attribute);
    })->with([
        'a blank label' => [fn () => ApplicationRequest::add(REJECTION_TEST_TEXT_REQUEST, RequestKind::Text, '   ', 1), 'label'],
        'a label on two lines' => [fn () => ApplicationRequest::add(REJECTION_TEST_TEXT_REQUEST, RequestKind::Text, "A bank letter\nsigned", 1), 'label'],
        'a label one character too long' => [fn () => ApplicationRequest::add(REJECTION_TEST_TEXT_REQUEST, RequestKind::Text, str_repeat('a', 201), 1), 'label'],
        'a position below zero' => [fn () => ApplicationRequest::add(REJECTION_TEST_FILE_REQUEST, RequestKind::File, 'A bank letter', -1), 'position'],
        'a position past the form' => [fn () => ApplicationRequest::add(REJECTION_TEST_FILE_REQUEST, RequestKind::File, 'A bank letter', 10001), 'position'],
    ]);

    it('takes an Arabic label of exactly 200 characters, and positions 0 and 10,000', function () {
        expect(mb_strlen(ApplicationRequest::add(REJECTION_TEST_TEXT_REQUEST, RequestKind::Text, str_repeat('خ', 200), 0)->label))->toBe(200)
            ->and(ApplicationRequest::add(REJECTION_TEST_TEXT_REQUEST, RequestKind::Text, 'A bank letter', 0)->position)->toBe(0)
            ->and(ApplicationRequest::add(REJECTION_TEST_TEXT_REQUEST, RequestKind::Text, 'A bank letter', 10000)->position)->toBe(10000);
    });
});

describe('answering a request (amendments 4 and 5)', function () {
    it('takes text for a text request and a file for a file request', function () {
        $last = rejectionTestRejected([], rejectionTestRequests());
        $draft = rejectionTestNextDraft();
        $draft->pullChanges();

        expect($draft->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText(text: "Al Noor Trading Establishment\nBranch 2")))->toBeNull()
            ->and($draft->answer($last, strtoupper(REJECTION_TEST_FILE_REQUEST), RequestAnswer::file(strtoupper(REJECTION_TEST_FILE_REQUEST), REJECTION_TEST_NEW_FILE)))->toBeNull()
            ->and($draft->answers()[REJECTION_TEST_TEXT_REQUEST]->text?->value)->toBe("Al Noor Trading Establishment\nBranch 2")
            ->and($draft->answers()[REJECTION_TEST_FILE_REQUEST]->mediaId)->toBe(REJECTION_TEST_NEW_FILE)
            ->and($draft->pullChanges())->toBe(['answers']);
    });

    it('replaces an answer given again, and says which file it replaced', function () {
        $last = rejectionTestRejected([], rejectionTestRequests());
        $draft = rejectionTestNextDraft();

        expect($draft->answer($last, REJECTION_TEST_FILE_REQUEST, RequestAnswer::file(REJECTION_TEST_FILE_REQUEST, REJECTION_TEST_NEW_FILE)))->toBeNull()
            ->and($draft->answer($last, REJECTION_TEST_FILE_REQUEST, RequestAnswer::file(REJECTION_TEST_FILE_REQUEST, REJECTION_TEST_NEWER_FILE)))->toBe(REJECTION_TEST_NEW_FILE)
            ->and($draft->answers()[REJECTION_TEST_FILE_REQUEST]->mediaId)->toBe(REJECTION_TEST_NEWER_FILE)
            ->and($draft->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText(text: 'First')))->toBeNull()
            // Text replaced by text lets go of no file.
            ->and($draft->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText(text: 'Second')))->toBeNull()
            ->and($draft->answers()[REJECTION_TEST_TEXT_REQUEST]->text?->value)->toBe('Second')
            ->and($draft->answers())->toHaveCount(2);
    });

    it('refuses an answer of the other kind', function (string $requestId, RequestAnswer $answer) {
        $last = rejectionTestRejected([], rejectionTestRequests());
        $draft = rejectionTestNextDraft();

        expect(fn () => $draft->answer($last, $requestId, $answer))->toThrow(AnswerKindMismatch::class)
            ->and($draft->answers())->toBe([]);
    })->with([
        'text for a file' => [REJECTION_TEST_FILE_REQUEST, RequestAnswer::text(REJECTION_TEST_FILE_REQUEST, Remark::of('answer', 'It is in the post.'))],
        'a file for text' => [REJECTION_TEST_TEXT_REQUEST, RequestAnswer::file(REJECTION_TEST_TEXT_REQUEST, REJECTION_TEST_NEW_FILE)],
    ]);

    it('finds no request that is not one of the last rejection\'s', function (Closure $lastSent, string $requestId) {
        $draft = rejectionTestNextDraft();

        expect(fn () => $draft->answer($lastSent(), $requestId, rejectionTestText($requestId)))->toThrow(RequestNotFound::class)
            ->and($draft->answers())->toBe([]);
    })->with([
        'one it did not make' => [fn () => rejectionTestRejected([], rejectionTestRequests()), '01j8z3k4m5n6p7q8r9s0t1v3r9'],
        'nothing sent before' => [fn () => null, REJECTION_TEST_TEXT_REQUEST],
        // Carrying the request, so only its state can refuse it.
        'an approval' => [fn () => rejectionTestReconstituted(ApplicationState::Approved, [], rejectionTestRequests()), REJECTION_TEST_TEXT_REQUEST],
    ]);

    it('keeps an answer only under the request it was given for', function () {
        $last = rejectionTestRejected([], rejectionTestRequests());

        // A file, as the file request asks, so only the request id can refuse it.
        expect(fn () => rejectionTestNextDraft()->answer($last, REJECTION_TEST_FILE_REQUEST, RequestAnswer::file(REJECTION_TEST_TEXT_REQUEST, REJECTION_TEST_NEW_FILE)))
            ->toThrow(LogicException::class, 'cannot be kept under another');
    });

    it('refuses answer text the company could not have meant', function (string $text) {
        $error = null;

        try {
            rejectionTestText(text: $text);
        } catch (InvalidCompanyAttribute $caught) {
            $error = $caught;
        }

        expect($error?->attribute)->toBe('answer');
    })->with([
        'one character too long' => [str_repeat('a', 1001)],
        'a tab' => ["Al Noor\tTrading"],
    ]);

    it('answers only in a draft', function () {
        $last = rejectionTestRejected([], rejectionTestRequests());
        $sent = rejectionTestNextDraft();
        $sent->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText());
        $sent->answer($last, REJECTION_TEST_FILE_REQUEST, RequestAnswer::file(REJECTION_TEST_FILE_REQUEST, REJECTION_TEST_NEW_FILE));
        rejectionTestSend($sent, $last);

        expect(fn () => $sent->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText(text: 'Changed')))->toThrow(ApplicationNotEditable::class)
            ->and($sent->answers()[REJECTION_TEST_TEXT_REQUEST]->text?->value)->toBe('Al Noor Trading Establishment');
    });

    it('takes an answer out of a draft, and says which file it removed', function () {
        $last = rejectionTestRejected([], rejectionTestRequests());
        $draft = rejectionTestNextDraft();
        $draft->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText());
        $draft->answer($last, REJECTION_TEST_FILE_REQUEST, RequestAnswer::file(REJECTION_TEST_FILE_REQUEST, REJECTION_TEST_NEW_FILE));
        $draft->pullChanges();

        expect($draft->removeAnswer(strtoupper(REJECTION_TEST_FILE_REQUEST)))->toBe(REJECTION_TEST_NEW_FILE)
            ->and($draft->pullChanges())->toBe(['answers'])
            ->and($draft->removeAnswer(REJECTION_TEST_TEXT_REQUEST))->toBeNull()
            ->and($draft->pullChanges())->toBe(['answers'])
            ->and($draft->answers())->toBe([])
            // Nothing left to remove: nothing changed.
            ->and($draft->removeAnswer(REJECTION_TEST_TEXT_REQUEST))->toBeNull()
            ->and($draft->pullChanges())->toBe([]);
    });

    it('takes no answer out of an application sent', function () {
        $last = rejectionTestRejected([], rejectionTestRequests());
        $sent = rejectionTestNextDraft();
        $sent->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText());
        $sent->answer($last, REJECTION_TEST_FILE_REQUEST, RequestAnswer::file(REJECTION_TEST_FILE_REQUEST, REJECTION_TEST_NEW_FILE));
        rejectionTestSend($sent, $last);

        expect(fn () => $sent->removeAnswer(REJECTION_TEST_FILE_REQUEST))->toThrow(ApplicationNotEditable::class)
            ->and($sent->answers())->toHaveCount(2);
    });
});

describe('the last application sent is the caller\'s to get right', function () {
    it('treats one of another company, or one not decided yet, as a bug', function (Closure $act, string $message) {
        expect($act)->toThrow(LogicException::class, $message);
    })->with([
        'answering, after another company\'s rejection' => [fn () => rejectionTestNextDraft()->answer(
            rejectionTestRejected([], rejectionTestRequests(), REJECTION_TEST_OTHER_COMPANY),
            REJECTION_TEST_TEXT_REQUEST,
            rejectionTestText(),
        ), 'belongs to another company'],
        'answering, while the last one waits' => [fn () => rejectionTestNextDraft()->answer(rejectionTestSent(), REJECTION_TEST_TEXT_REQUEST, rejectionTestText()), 'not decided yet'],
        // Its flag would refuse the draft if it were read first.
        'sending, after another company\'s rejection' => [fn () => rejectionTestSend(rejectionTestNextDraft(), rejectionTestRejected([ApplicationFlag::field(FlaggedField::Name)], [], REJECTION_TEST_OTHER_COMPANY)), 'belongs to another company'],
        'sending, while the last one waits' => [fn () => rejectionTestSend(rejectionTestNextDraft(), rejectionTestSent()), 'not decided yet'],
    ]);
});

describe('sending after a rejection (amendments 4, 5 and 6)', function () {
    it('refuses a flagged field that holds what was sent, and takes one that differs — letter case included', function (FlaggedField $field, string $value, bool $replaced) {
        $last = rejectionTestRejected([ApplicationFlag::field($field)]);
        $draft = rejectionTestNextDraft();
        rejectionTestFillIn($draft, [$field->value => $value]);

        if ($replaced) {
            rejectionTestSend($draft, $last);

            expect($draft->state())->toBe(ApplicationState::Submitted);

            return;
        }

        expect(fn () => rejectionTestSend($draft, $last))->toThrow(FlaggedItemNotReplaced::class)
            ->and($draft->state())->toBe(ApplicationState::Draft);
    })->with([
        'the name, unchanged' => [FlaggedField::Name, 'Al Noor Trading', false],
        'the name, the same once trimmed' => [FlaggedField::Name, "  Al Noor Trading \n", false],
        'the name, in other letters' => [FlaggedField::Name, 'AL NOOR TRADING', true],
        'the type, unchanged' => [FlaggedField::CompanyType, 'Trading house', false],
        'the type, the same once trimmed' => [FlaggedField::CompanyType, ' Trading house ', false],
        'the type, in other letters' => [FlaggedField::CompanyType, 'trading house', true],
        'the CR number, unchanged' => [FlaggedField::CrNumber, 'CR-1010123456', false],
        'the CR number, the same once trimmed' => [FlaggedField::CrNumber, ' CR-1010123456 ', false],
        'the CR number, in other letters' => [FlaggedField::CrNumber, 'cr-1010123456', true],
        'the tax number, unchanged' => [FlaggedField::TaxNumber, 'VAT-300123456700003', false],
        'the tax number, the same once trimmed' => [FlaggedField::TaxNumber, "VAT-300123456700003\n", false],
        'the tax number, in other letters' => [FlaggedField::TaxNumber, 'vat-300123456700003', true],
        'the address, unchanged' => [FlaggedField::Address, "12 Industrial Road\nBlock 4", false],
        'the address, the same once trimmed' => [FlaggedField::Address, "  12 Industrial Road\r\nBlock 4\n", false],
        'the address, in other letters' => [FlaggedField::Address, "12 INDUSTRIAL ROAD\nBLOCK 4", true],
    ]);

    it('refuses a flagged type picked from the list when the draft picks it again, and takes another — listed or "Other"', function (CompanyTypeChoice $sent, CompanyTypeChoice $next, bool $replaced) {
        $last = rejectionTestRejected([ApplicationFlag::field(FlaggedField::CompanyType)], [], REJECTION_TEST_COMPANY, $sent);
        $draft = rejectionTestNextDraft();
        rejectionTestFillIn($draft, [], $next);

        if ($replaced) {
            rejectionTestSend($draft, $last);

            expect($draft->state())->toBe(ApplicationState::Submitted);

            return;
        }

        expect(fn () => rejectionTestSend($draft, $last))->toThrow(FlaggedItemNotReplaced::class)
            ->and($draft->state())->toBe(ApplicationState::Draft);
    })->with([
        'a listed type, picked again' => [CompanyTypeChoice::listed(REJECTION_TEST_LLC), CompanyTypeChoice::listed(REJECTION_TEST_LLC), false],
        'a listed type, picked again in capitals' => [CompanyTypeChoice::listed(REJECTION_TEST_LLC), CompanyTypeChoice::listed(strtoupper(REJECTION_TEST_LLC)), false],
        // Neither holds any words of its own, so only the type's id can tell them apart.
        'a listed type, another one picked' => [CompanyTypeChoice::listed(REJECTION_TEST_LLC), CompanyTypeChoice::listed(REJECTION_TEST_JSC), true],
        'a listed type, "Other" chosen' => [CompanyTypeChoice::listed(REJECTION_TEST_LLC), CompanyTypeChoice::other('Trading house'), true],
        '"Other", a listed type picked' => [CompanyTypeChoice::other('Trading house'), CompanyTypeChoice::listed(REJECTION_TEST_LLC), true],
    ]);

    it('refuses a flagged document with no file, or with the file that was sent, and takes a new one', function () {
        // An optional type, so a missing file is the flag's refusal, not the required type's.
        $last = rejectionTestRejected([ApplicationFlag::document(REJECTION_TEST_LETTER)]);
        $draft = rejectionTestNextDraft();

        expect(fn () => rejectionTestSend($draft, $last))->toThrow(FlaggedItemNotReplaced::class);

        $draft->detach(REJECTION_TEST_LETTER);

        expect(fn () => rejectionTestSend($draft, $last))->toThrow(FlaggedItemNotReplaced::class);

        $draft->attach(REJECTION_TEST_LETTER, REJECTION_TEST_NEW_FILE, CarbonImmutable::now());
        rejectionTestSend($draft, $last);

        expect($draft->state())->toBe(ApplicationState::Submitted);
    });

    it('lets a flag on a document type deactivated since stop blocking, and still refuses its old file', function () {
        $last = rejectionTestRejected([ApplicationFlag::document(REJECTION_TEST_LETTER)]);
        $types = rejectionTestDocumentTypes();
        $types[2]->deactivate(InactiveTypeDisplay::Hidden);
        $draft = rejectionTestNextDraft();

        expect(fn () => rejectionTestSend($draft, $last, $types))->toThrow(DocumentNoLongerAccepted::class, REJECTION_TEST_LETTER);

        $draft->detach(REJECTION_TEST_LETTER);
        rejectionTestSend($draft, $last, $types);

        expect($draft->state())->toBe(ApplicationState::Submitted);
    });

    it('refuses a draft with a request unanswered, and sends it once each is answered as asked', function () {
        $last = rejectionTestRejected([], rejectionTestRequests());
        $draft = rejectionTestNextDraft();
        $draft->answer($last, REJECTION_TEST_TEXT_REQUEST, rejectionTestText());

        expect(fn () => rejectionTestSend($draft, $last))->toThrow(RequestNotAnswered::class, REJECTION_TEST_FILE_REQUEST)
            ->and($draft->state())->toBe(ApplicationState::Draft);

        $draft->answer($last, REJECTION_TEST_FILE_REQUEST, RequestAnswer::file(REJECTION_TEST_FILE_REQUEST, REJECTION_TEST_NEW_FILE));
        rejectionTestSend($draft, $last);

        expect($draft->state())->toBe(ApplicationState::Submitted);
    });

    it('applies nothing of an approval, and nothing when nothing was sent before', function () {
        // Carrying a flag and a request, so only its state keeps them from applying.
        $approved = rejectionTestReconstituted(ApplicationState::Approved, [ApplicationFlag::field(FlaggedField::Name)], rejectionTestRequests());
        $afterApproval = rejectionTestNextDraft();
        rejectionTestSend($afterApproval, $approved);

        $first = Application::draft(REJECTION_TEST_FIRST, REJECTION_TEST_CUSTOMER, null);
        rejectionTestFillIn($first);
        $first->attach(REJECTION_TEST_VAT, REJECTION_TEST_VAT_FILE, CarbonImmutable::now());
        $first->attach(REJECTION_TEST_CR, REJECTION_TEST_CR_FILE, CarbonImmutable::now());
        rejectionTestSend($first, null);

        expect($afterApproval->state())->toBe(ApplicationState::Submitted)
            ->and($first->state())->toBe(ApplicationState::Submitted);
    });
});
