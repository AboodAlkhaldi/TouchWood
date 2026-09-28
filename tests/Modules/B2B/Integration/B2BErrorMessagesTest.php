<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Carbon\CarbonImmutable;
use Modules\B2B\Domain\Exception\AnswerKindMismatch;
use Modules\B2B\Domain\Exception\B2BError;
use Modules\B2B\Domain\Exception\DocumentNoLongerAccepted;
use Modules\B2B\Domain\Exception\FlaggedItemNotReplaced;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\RequestNotAnswered;
use Modules\B2B\Domain\Exception\RequestNotFound;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
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
| What the person reads when sending or answering is refused (b2b.md §7, amendment 6(e)): each new
| refusal in their language, through the same FormErrors every screen uses, and never the English
| written into the exception for the log.
*/

const B2B_ERROR_MESSAGES_STORE = '01j8z3k4m5n6p7q8r9s0t1v4e1';

const B2B_ERROR_MESSAGES_COMPANY = '01j8z3k4m5n6p7q8r9s0t1v4c1';

const B2B_ERROR_MESSAGES_CUSTOMER = '01j8z3k4m5n6p7q8r9s0t1v4k1';

const B2B_ERROR_MESSAGES_LLC = '01j8z3k4m5n6p7q8r9s0t1v4t1';

const B2B_ERROR_MESSAGES_VAT = '01j8z3k4m5n6p7q8r9s0t1v4d1';

const B2B_ERROR_MESSAGES_LETTER = '01j8z3k4m5n6p7q8r9s0t1v4d2';

const B2B_ERROR_MESSAGES_REQUEST = '01j8z3k4m5n6p7q8r9s0t1v4r1';

/** The owner's sentence, word for word (amendment 6(e)). */
const B2B_ERROR_MESSAGES_FLAGGED_EN = 'Replace every item marked in the last decision before you send the application.';

/** Its Arabic, as the language file gives it. */
const B2B_ERROR_MESSAGES_FLAGGED_AR = 'استبدل كل عنصر حُدّد في القرار الأخير قبل إرسال الطلب.';

/**
 * @return list<DocumentType> a required type and an optional one
 */
function b2bErrorMessagesDocumentTypes(): array
{
    return [
        DocumentType::add(B2B_ERROR_MESSAGES_VAT, B2B_ERROR_MESSAGES_STORE, TypeName::of('شهادة ضريبة القيمة المضافة', 'VAT certificate'), 1, isRequired: true),
        DocumentType::add(B2B_ERROR_MESSAGES_LETTER, B2B_ERROR_MESSAGES_STORE, TypeName::of('خطاب تفويض', 'Letter of authorisation'), 2, isRequired: false),
    ];
}

/**
 * A complete draft holding a file under each type: the company's first, or its next.
 */
function b2bErrorMessagesDraft(string $id, ?string $companyId, ?CompanyTypeChoice $type = null): Application
{
    $draft = Application::draft($id, B2B_ERROR_MESSAGES_CUSTOMER, $companyId);
    $draft->describe(
        CompanyName::of('Al Noor Trading'),
        $type ?? CompanyTypeChoice::listed(B2B_ERROR_MESSAGES_LLC),
        RegistrationNumber::of('cr_number', '1010123456'),
        RegistrationNumber::of('tax_number', '300123456700003'),
        CompanyAddress::of('12 Industrial Road'),
        null,
    );
    $draft->attach(B2B_ERROR_MESSAGES_VAT, '01j8z3k4m5n6p7q8r9s0t1v4m1', CarbonImmutable::now());
    $draft->attach(B2B_ERROR_MESSAGES_LETTER, '01j8z3k4m5n6p7q8r9s0t1v4m2', CarbonImmutable::now());

    return $draft;
}

/**
 * @param  list<DocumentType>|null  $documentTypes
 */
function b2bErrorMessagesSend(Application $draft, ?Application $lastSent, ?array $documentTypes = null): void
{
    $draft->submit(
        B2B_ERROR_MESSAGES_COMPANY,
        [CompanyType::add(B2B_ERROR_MESSAGES_LLC, B2B_ERROR_MESSAGES_STORE, TypeName::of('شركة ذات مسؤولية محدودة', 'Limited Liability Company'), 1)],
        $documentTypes ?? b2bErrorMessagesDocumentTypes(),
        $lastSent,
        CarbonImmutable::now(),
    );
}

/**
 * The company's first application, sent and rejected with these flags and requests.
 *
 * @param  list<ApplicationFlag>  $flags
 * @param  list<ApplicationRequest>  $requests
 */
function b2bErrorMessagesRejected(array $flags = [], array $requests = []): Application
{
    $application = b2bErrorMessagesDraft('01j8z3k4m5n6p7q8r9s0t1v4a1', null);
    b2bErrorMessagesSend($application, null);
    $application->reject('01j8z3k4m5n6p7q8r9s0t1v4s1', Remark::of('reason', 'Wrong.'), CarbonImmutable::now(), $flags, $requests);

    return $application;
}

function b2bErrorMessagesNextDraft(): Application
{
    return b2bErrorMessagesDraft('01j8z3k4m5n6p7q8r9s0t1v4a2', B2B_ERROR_MESSAGES_COMPANY);
}

function b2bErrorMessagesRequest(): ApplicationRequest
{
    return ApplicationRequest::add(B2B_ERROR_MESSAGES_REQUEST, RequestKind::File, 'A bank letter confirming the account', 1);
}

/**
 * The B2B error the work throws, or a failure when it throws none.
 */
function b2bErrorMessagesCaught(Closure $work): B2BError
{
    try {
        $work();
    } catch (B2BError $error) {
        return $error;
    }

    throw new LogicException('Nothing was refused.');
}

/**
 * One line of B2B's error file in that language, read from the file itself.
 */
function b2bErrorMessagesLine(string $locale, string $key): string
{
    /** @var array<string, array<string, string>> $lines */
    $lines = require base_path("src/Modules/B2B/Presentation/lang/{$locale}/errors.php");

    return $lines[$key]['detail'];
}

it('tells the person one general sentence for any flagged item not replaced, a field or a document alike, in their language', function () {
    $field = b2bErrorMessagesCaught(fn () => b2bErrorMessagesSend(b2bErrorMessagesNextDraft(), b2bErrorMessagesRejected([ApplicationFlag::field(FlaggedField::Name)])));
    $document = b2bErrorMessagesCaught(fn () => b2bErrorMessagesSend(b2bErrorMessagesNextDraft(), b2bErrorMessagesRejected([ApplicationFlag::document(B2B_ERROR_MESSAGES_LETTER)])));

    expect($field)->toBeInstanceOf(FlaggedItemNotReplaced::class)
        ->and($document)->toBeInstanceOf(FlaggedItemNotReplaced::class);

    app()->setLocale('en');

    expect(FormErrors::message($field))->toBe(B2B_ERROR_MESSAGES_FLAGGED_EN)
        ->and(FormErrors::message($document))->toBe(B2B_ERROR_MESSAGES_FLAGGED_EN);

    app()->setLocale('ar');

    expect(FormErrors::message($field))->toBe(B2B_ERROR_MESSAGES_FLAGGED_AR)
        ->and(FormErrors::message($document))->toBe(B2B_ERROR_MESSAGES_FLAGGED_AR)
        // Nothing to fill in: it names no item.
        ->and(B2B_ERROR_MESSAGES_FLAGGED_AR)->not->toContain(':')
        ->and(B2B_ERROR_MESSAGES_FLAGGED_EN)->not->toContain(':');
});

it('says a company type from another store\'s list is not valid, in the person\'s language', function () {
    $error = b2bErrorMessagesCaught(fn () => b2bErrorMessagesSend(
        b2bErrorMessagesDraft('01j8z3k4m5n6p7q8r9s0t1v4a3', null, CompanyTypeChoice::listed('01j8z3k4m5n6p7q8r9s0t1v4t9')),
        null,
    ));

    expect($error)->toBeInstanceOf(InvalidCompanyAttribute::class)
        ->and($error instanceof InvalidCompanyAttribute ? $error->attribute : null)->toBe('company_type');

    app()->setLocale('en');
    expect(FormErrors::message($error))->toBe('The company type is not valid.');

    app()->setLocale('ar');
    expect(FormErrors::message($error))->toBe('قيمة نوع الشركة غير صالحة.');
});

it('translates each new refusal, and never shows the English written for the log', function (Closure $work, string $class, string $key) {
    $error = b2bErrorMessagesCaught($work);

    expect($error::class)->toBe($class);

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $message = FormErrors::message($error);

        expect($message)->toBe(b2bErrorMessagesLine($locale, $key))
            ->and($message === $error->getMessage())->toBeFalse();
    }

    expect(b2bErrorMessagesLine('ar', $key))->not->toBe(b2bErrorMessagesLine('en', $key));
})->with([
    'a request not answered' => [
        fn () => b2bErrorMessagesSend(b2bErrorMessagesNextDraft(), b2bErrorMessagesRejected([], [b2bErrorMessagesRequest()])),
        RequestNotAnswered::class,
        'request_not_answered',
    ],
    'a document no longer accepted' => [
        function () {
            $types = b2bErrorMessagesDocumentTypes();
            $types[1]->deactivate(InactiveTypeDisplay::Greyed);
            b2bErrorMessagesSend(b2bErrorMessagesDraft('01j8z3k4m5n6p7q8r9s0t1v4a4', null), null, $types);
        },
        DocumentNoLongerAccepted::class,
        'document_no_longer_accepted',
    ],
    'a request that is not the last decision\'s' => [
        fn () => b2bErrorMessagesNextDraft()->answer(null, B2B_ERROR_MESSAGES_REQUEST, RequestAnswer::file(B2B_ERROR_MESSAGES_REQUEST, '01j8z3k4m5n6p7q8r9s0t1v4m3')),
        RequestNotFound::class,
        'request_not_found',
    ],
    'an answer of the other kind' => [
        fn () => b2bErrorMessagesNextDraft()->answer(
            b2bErrorMessagesRejected([], [b2bErrorMessagesRequest()]),
            B2B_ERROR_MESSAGES_REQUEST,
            RequestAnswer::text(B2B_ERROR_MESSAGES_REQUEST, Remark::of('answer', 'It is in the post.')),
        ),
        AnswerKindMismatch::class,
        'answer_kind_mismatch',
    ],
]);
