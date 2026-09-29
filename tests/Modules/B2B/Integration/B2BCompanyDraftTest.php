<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequest;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequestHandler;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\RemoveApplicationAnswer\RemoveApplicationAnswer;
use Modules\B2B\Application\Command\RemoveApplicationAnswer\RemoveApplicationAnswerHandler;
use Modules\B2B\Application\Command\RemoveApplicationDocument\RemoveApplicationDocument;
use Modules\B2B\Application\Command\RemoveApplicationDocument\RemoveApplicationDocumentHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Domain\Exception\AnswerKindMismatch;
use Modules\B2B\Domain\Exception\ApplicationAlreadyOpen;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\DocumentTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\RequestNotFound;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 3b: the draft's actions (b2b.md §1.2, §1.4, §3.1; amendments 4, 5 and 9). They name no
| application — each acts on the signed-in account's one open application.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(B2BFixtures::uploads());
});

function companyDraftStart(): string
{
    return app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
}

/**
 * @param  array<string, string|null>  $fields
 */
function companyDraftSave(array $fields): void
{
    app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft($fields));
}

function companyDraftAttach(string $documentTypeId): void
{
    app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($documentTypeId, B2BFixtures::pdf(), 'certificate.pdf'));
}

function companyDraftOpen(string $customerId): Application
{
    return app(ApplicationRepository::class)->openFor($customerId) ?? throw new LogicException('No open application.');
}

function companyDraftMediaExists(string $mediaId): bool
{
    return DB::table('platform.media')->where('id', $mediaId)->exists();
}

/**
 * A company whose first application was rejected with these requests, acting as its account with a
 * new draft open: the draft a reapplication fills in.
 *
 * @param  list<ApplicationRequest>  $requests
 * @param  list<ApplicationFlag>  $flags
 * @return array{0: string, 1: Application} the account, and the rejected application
 */
function companyDraftAfterRejection(array $requests = [], array $flags = []): array
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [, $rejected] = B2BFixtures::rejected($customerId, $flags, $requests);
    Fx::actAsCustomer($customerId);
    companyDraftStart();

    return [$customerId, $rejected];
}

/**
 * @return list<string> the home store's active document type ids
 */
function companyDraftDocumentTypeIds(): array
{
    return array_values(array_map(
        static fn ($type): string => $type->id(),
        array_filter(B2BFixtures::documentTypes(), static fn ($type): bool => $type->isActive()),
    ));
}

describe('starting a draft (§3.1, amendment 5)', function () {
    it('starts an empty first draft, and there is still no company (scenario 2)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);

        $draftId = companyDraftStart();
        $draft = companyDraftOpen($customerId);

        expect($draft->id())->toBe($draftId)
            ->and($draft->state()->value)->toBe('DRAFT')
            ->and($draft->companyId())->toBeNull()
            ->and($draft->name())->toBeNull()
            ->and($draft->documents())->toBe([])
            ->and(DB::table('b2b.companies')->where('customer_id', $customerId)->exists())->toBeFalse();
    });

    it('returns the open draft instead of starting another (scenario 9k)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);

        $first = companyDraftStart();

        expect(companyDraftStart())->toBe($first)
            ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->count())->toBe(1);
    });

    it('refuses to start one while a sent application waits (scenario 9k)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::sent($customerId);
        Fx::actAsCustomer($customerId);

        expect(fn () => companyDraftStart())->toThrow(ApplicationAlreadyOpen::class)
            ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->count())->toBe(1);
    });

    it('starts a later draft from the company as it is now and the files of the last application sent (scenarios 8, 9g)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company, $rejected] = B2BFixtures::rejected($customerId);
        // Since it was sent: the address moved, and staff corrected the type.
        $company->moveTo(CompanyAddress::of("Olaya Street\nRiyadh"));
        $corrected = B2BFixtures::companyTypes()[0]->id();
        $company->correctType(CompanyTypeChoice::listed($corrected), B2BFixtures::companyTypes());
        app(CompanyRepository::class)->update($company);
        // A note the rejected application really carries, so its not being copied is shown (lesson 80).
        DB::table('b2b.applications')->where('id', $rejected->id())->update(['note' => 'Please call before visiting.']);
        Fx::actAsCustomer($customerId);

        companyDraftStart();
        $draft = companyDraftOpen($customerId);
        $sent = app(ApplicationRepository::class)->find($rejected->id());

        expect($draft->id())->not->toBe($rejected->id())
            ->and($draft->companyId())->toBe($company->id())
            ->and($draft->address()?->value)->toBe("Olaya Street\nRiyadh")
            ->and($draft->type()?->typeId)->toBe($corrected)
            ->and($draft->name()?->value)->toBe('Al Noor Trading')
            // Each application's note is its own (amendment 5).
            ->and($sent?->note()?->value)->toBe('Please call before visiting.')
            ->and($draft->note())->toBeNull()
            // The same files, under the same types, with the dates they were uploaded.
            ->and(array_map(static fn ($document): array => [$document->mediaId, $document->uploadedAt->format(DATE_ATOM)], $draft->documents()))
            ->toBe(array_map(static fn ($document): array => [$document->mediaId, $document->uploadedAt->format(DATE_ATOM)], $rejected->documents()))
            // The rejected application keeps its own copies untouched.
            ->and($sent?->address()?->value)->toBe("King Fahd Road\nRiyadh")
            ->and($sent?->state()->value)->toBe('REJECTED');
    });

    it('refuses a suspended company, which may only discard (scenario 9l)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        B2BFixtures::suspend($company);
        Fx::actAsCustomer($customerId);

        expect(fn () => companyDraftStart())->toThrow(CompanySuspended::class)
            ->and(app(ApplicationRepository::class)->openFor($customerId))->toBeNull();
    });

    it('refuses a suspended company even with its draft open, and leaves that draft as it is (amendment 9(e))', function () {
        [$customerId] = companyDraftAfterRejection();
        $draftId = companyDraftOpen($customerId)->id();
        B2BFixtures::suspend(app(CompanyRepository::class)->forCustomer($customerId) ?? throw new LogicException);

        expect(fn () => companyDraftStart())->toThrow(CompanySuspended::class)
            ->and(companyDraftOpen($customerId)->id())->toBe($draftId)
            ->and(companyDraftOpen($customerId)->state()->value)->toBe('DRAFT');
    });
});

describe('saving the draft (§1.2, §3.1, amendments 4 and 5)', function () {
    it('keeps everything typed when it is left and opened again, and changes only the fields sent (scenario 2)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();

        companyDraftSave(['name' => 'Al Noor Trading', 'cr_number' => '1010123456', 'address' => "King Fahd Road\nRiyadh", 'note' => 'Our first application.']);
        companyDraftSave(['tax_number' => '300123456700003']);

        $draft = companyDraftOpen($customerId);

        expect($draft->name()?->value)->toBe('Al Noor Trading')
            ->and($draft->crNumber()?->value)->toBe('1010123456')
            ->and($draft->taxNumber()?->value)->toBe('300123456700003')
            ->and($draft->address()?->value)->toBe("King Fahd Road\nRiyadh")
            ->and($draft->note()?->value)->toBe('Our first application.')
            ->and(DB::table('b2b.companies')->where('customer_id', $customerId)->exists())->toBeFalse();
    });

    it('clears a field sent empty', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        companyDraftSave(['name' => 'Al Noor Trading', 'cr_number' => '1010123456']);

        companyDraftSave(['name' => '  ', 'cr_number' => null]);
        $draft = companyDraftOpen($customerId);

        expect($draft->name())->toBeNull()
            ->and($draft->crNumber())->toBeNull();
    });

    it('refuses a wrong value on its own field when it is saved, and keeps what was there (scenario 9e)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        companyDraftSave(['cr_number' => '1010123456']);
        $error = null;

        try {
            companyDraftSave(['cr_number' => str_repeat('1', 51)]);
        } catch (InvalidCompanyAttribute $caught) {
            $error = $caught;
        }

        expect($error?->attribute)->toBe('cr_number')
            ->and(companyDraftOpen($customerId)->crNumber()?->value)->toBe('1010123456');
    });

    it('takes a listed type or Other, and never both', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        $listed = B2BFixtures::companyTypes()[0]->id();

        companyDraftSave(['company_type_id' => $listed]);
        $afterListed = companyDraftOpen($customerId)->type();
        companyDraftSave(['company_type_other' => 'Charitable foundation']);
        $afterOther = companyDraftOpen($customerId)->type();

        expect($afterListed?->typeId)->toBe($listed)
            ->and($afterOther?->typeId)->toBeNull()
            ->and($afterOther?->other)->toBe('Charitable foundation')
            ->and(fn () => companyDraftSave(['company_type_id' => $listed, 'company_type_other' => 'Charitable foundation']))->toThrow(InvalidCompanyAttribute::class);
    });

    it('refuses a newly chosen type that is not offered', function (Closure $type, string $error) {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();

        expect(fn () => companyDraftSave(['company_type_id' => $type()]))->toThrow($error)
            ->and(companyDraftOpen($customerId)->type())->toBeNull();
    })->with([
        'deactivated' => [function () {
            $type = B2BFixtures::companyTypes()[0];
            B2BFixtures::deactivate($type);

            return $type->id();
        }, CompanyTypeInactive::class],
        'another store\'s (amendment 6(d))' => [fn () => B2BFixtures::companyTypes('eg')[0]->id(), InvalidCompanyAttribute::class],
        'one that does not exist' => [fn () => strtolower((string) Str::ulid()), InvalidCompanyAttribute::class],
    ]);

    it('leaves a type the draft already holds when it is sent again, deactivated since or not (§1.3)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        $type = B2BFixtures::companyTypes()[0];
        companyDraftSave(['company_type_id' => $type->id()]);
        B2BFixtures::deactivate($type);

        companyDraftSave(['company_type_id' => $type->id(), 'name' => 'Al Noor Trading']);

        expect(companyDraftOpen($customerId)->type()?->typeId)->toBe($type->id())
            ->and(companyDraftOpen($customerId)->name()?->value)->toBe('Al Noor Trading');
    });

    it('saves before the email address is confirmed: only sending needs it (§1.2)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();

        companyDraftSave(['name' => 'Al Noor Trading']);

        expect(DB::table('access.customers')->where('id', $customerId)->value('email_verified_at'))->toBeNull()
            ->and(companyDraftOpen($customerId)->name()?->value)->toBe('Al Noor Trading');
    });

    it('writes nothing to the audit log (amendment 4)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        $levels = B2BFixtures::auditLevels();

        companyDraftSave(['name' => 'Al Noor Trading', 'address' => 'Riyadh']);

        expect($levels->getArrayCopy())->toBe([]);
    });

    it('refuses with no draft open, and once it is sent', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);

        expect(fn () => companyDraftSave(['name' => 'Al Noor Trading']))->toThrow(ApplicationNotFound::class);

        B2BFixtures::sent($customerId);

        expect(fn () => companyDraftSave(['name' => 'Al Noor Trading']))->toThrow(ApplicationNotEditable::class);
    });

    it('refuses a suspended company\'s draft (scenario 9l)', function () {
        [$customerId] = companyDraftAfterRejection();
        $company = app(CompanyRepository::class)->forCustomer($customerId);
        B2BFixtures::suspend($company ?? throw new LogicException);

        expect(fn () => companyDraftSave(['name' => 'Another name']))->toThrow(CompanySuspended::class)
            ->and(companyDraftOpen($customerId)->name()?->value)->toBe('Al Noor Trading');
    });
});

describe('the draft\'s papers (§1.4, amendments 4, 5 and 9(a))', function () {
    it('uploads a paper as a private file and replaces the one before, deleting it (scenario 9d)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        $typeId = companyDraftDocumentTypeIds()[0];

        companyDraftAttach($typeId);
        $first = companyDraftOpen($customerId)->documents()[$typeId]->mediaId;
        companyDraftAttach($typeId);
        $second = companyDraftOpen($customerId)->documents()[$typeId]->mediaId;

        expect($second)->not->toBe($first)
            ->and(DB::table('platform.media')->where('id', $second)->value('visibility'))->toBe('PRIVATE')
            ->and(companyDraftMediaExists($first))->toBeFalse()
            ->and(companyDraftOpen($customerId)->documents())->toHaveCount(1);
    });

    it('uploads before the email address is confirmed: only sending needs it (§1.2)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        $writes = B2BFixtures::mediaWrites();

        companyDraftAttach(companyDraftDocumentTypeIds()[0]);

        // Counted here, so the zero the refusals below assert is a real zero (lesson 44).
        expect(companyDraftOpen($customerId)->documents())->toHaveCount(1)
            ->and($writes->count())->toBe(1);
    });

    it('keeps a replaced file the last application sent still holds (scenario 9h)', function () {
        [$customerId, $rejected] = companyDraftAfterRejection();
        $typeId = companyDraftDocumentTypeIds()[0];
        $carried = $rejected->documents()[$typeId]->mediaId;

        companyDraftAttach($typeId);

        expect(companyDraftOpen($customerId)->documents()[$typeId]->mediaId)->not->toBe($carried)
            ->and(companyDraftMediaExists($carried))->toBeTrue()
            ->and(app(ApplicationRepository::class)->find($rejected->id())?->documents()[$typeId]->mediaId)->toBe($carried);
    });

    it('refuses a document type that is not offered before anything is stored (scenario 9h)', function (Closure $type) {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        $typeId = $type();
        $writes = B2BFixtures::mediaWrites();

        expect(fn () => companyDraftAttach($typeId))->toThrow(DocumentTypeInactive::class)
            ->and($writes->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(companyDraftOpen($customerId)->documents())->toBe([]);
    })->with([
        'deactivated' => [function () {
            $type = B2BFixtures::documentTypes()[0];
            B2BFixtures::deactivate($type, InactiveTypeDisplay::Greyed);

            return $type->id();
        }],
        'another store\'s' => [fn () => B2BFixtures::documentTypes('eg')[0]->id()],
        'one that does not exist' => [fn () => strtolower((string) Str::ulid())],
    ]);

    it('refuses an upload once the application is sent, before anything is stored', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::sent($customerId);
        Fx::actAsCustomer($customerId);
        $writes = B2BFixtures::mediaWrites();

        // The application itself would refuse too, but only after the file was stored (lesson 40).
        expect(fn () => companyDraftAttach(companyDraftDocumentTypeIds()[0]))->toThrow(ApplicationNotEditable::class)
            ->and($writes->count())->toBe(0);
    });

    it('refuses an upload into a suspended company\'s draft before anything is stored (scenario 9l)', function () {
        [$customerId] = companyDraftAfterRejection();
        B2BFixtures::suspend(app(CompanyRepository::class)->forCustomer($customerId) ?? throw new LogicException);
        $writes = B2BFixtures::mediaWrites();

        expect(fn () => companyDraftAttach(companyDraftDocumentTypeIds()[0]))->toThrow(CompanySuspended::class)
            ->and($writes->count())->toBe(0);
    });

    it('removes a paper, deleting a file only the draft holds', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();
        $typeId = companyDraftDocumentTypeIds()[0];
        companyDraftAttach($typeId);
        $mediaId = companyDraftOpen($customerId)->documents()[$typeId]->mediaId;

        app(RemoveApplicationDocumentHandler::class)->handle(new RemoveApplicationDocument($typeId));

        expect(companyDraftOpen($customerId)->documents())->toBe([])
            ->and(companyDraftMediaExists($mediaId))->toBeFalse();
    });

    it('removes a carried paper from the draft only, and the last application sent keeps it (§1.4)', function () {
        [$customerId, $rejected] = companyDraftAfterRejection();
        $typeId = companyDraftDocumentTypeIds()[0];
        $carried = $rejected->documents()[$typeId]->mediaId;

        app(RemoveApplicationDocumentHandler::class)->handle(new RemoveApplicationDocument($typeId));

        expect(companyDraftOpen($customerId)->documents())->not->toHaveKey($typeId)
            ->and(companyDraftMediaExists($carried))->toBeTrue();
    });

    it('refuses removing a paper while suspended: the draft is frozen (amendment 9(a))', function () {
        [$customerId] = companyDraftAfterRejection();
        $typeId = companyDraftDocumentTypeIds()[0];
        B2BFixtures::suspend(app(CompanyRepository::class)->forCustomer($customerId) ?? throw new LogicException);

        expect(fn () => app(RemoveApplicationDocumentHandler::class)->handle(new RemoveApplicationDocument($typeId)))->toThrow(CompanySuspended::class)
            ->and(companyDraftOpen($customerId)->documents())->toHaveKey($typeId);
    });
});

describe('answering the last rejection\'s requests (§1.2, amendments 4, 5 and 9(a))', function () {
    it('answers a text request and a file request as they ask', function () {
        $text = strtolower((string) Str::ulid());
        $file = strtolower((string) Str::ulid());
        [$customerId] = companyDraftAfterRejection([
            ApplicationRequest::add($text, RequestKind::Text, 'Who signs for the company?', 1),
            ApplicationRequest::add($file, RequestKind::File, 'A bank letter confirming the account', 2),
        ]);

        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($text, text: 'Our general manager, Sara Ali.'));
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($file, path: B2BFixtures::pdf(), originalFilename: 'bank-letter.pdf'));
        $answers = companyDraftOpen($customerId)->answers();

        expect($answers[$text]->text?->value)->toBe('Our general manager, Sara Ali.')
            ->and($answers[$file]->mediaId)->not->toBeNull()
            ->and(DB::table('platform.media')->where('id', $answers[$file]->mediaId)->value('visibility'))->toBe('PRIVATE');
    });

    it('replaces a file answer, deleting the file before', function () {
        $file = strtolower((string) Str::ulid());
        [$customerId] = companyDraftAfterRejection([ApplicationRequest::add($file, RequestKind::File, 'A bank letter', 1)]);
        $answer = fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($file, path: B2BFixtures::pdf(), originalFilename: 'bank-letter.pdf'));

        $answer();
        $first = companyDraftOpen($customerId)->answers()[$file]->mediaId;
        $answer();

        expect(companyDraftOpen($customerId)->answers()[$file]->mediaId)->not->toBe($first)
            ->and(companyDraftMediaExists((string) $first))->toBeFalse();
    });

    it('refuses a request that is not the last rejection\'s, before anything is stored', function () {
        [$customerId] = companyDraftAfterRejection([ApplicationRequest::add(strtolower((string) Str::ulid()), RequestKind::File, 'A bank letter', 1)]);
        $writes = B2BFixtures::mediaWrites();

        expect(fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest(strtolower((string) Str::ulid()), path: B2BFixtures::pdf(), originalFilename: 'x.pdf')))
            ->toThrow(RequestNotFound::class)
            ->and($writes->count())->toBe(0)
            ->and(companyDraftOpen($customerId)->answers())->toBe([]);
    });

    it('refuses a request on a first draft, which has no rejection before it', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companyDraftStart();

        expect(fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest(strtolower((string) Str::ulid()), text: 'An answer')))
            ->toThrow(RequestNotFound::class);
    });

    it('refuses the wrong kind of answer, before anything is stored', function (string $kind) {
        $id = strtolower((string) Str::ulid());
        [$customerId] = companyDraftAfterRejection([ApplicationRequest::add($id, RequestKind::from($kind), 'Something asked for', 1)]);
        $writes = B2BFixtures::mediaWrites();

        $answer = $kind === 'TEXT'
            ? new AnswerApplicationRequest($id, path: B2BFixtures::pdf(), originalFilename: 'x.pdf')
            : new AnswerApplicationRequest($id, text: 'Text where a file was asked for');

        expect(fn () => app(AnswerApplicationRequestHandler::class)->handle($answer))->toThrow(AnswerKindMismatch::class)
            ->and($writes->count())->toBe(0)
            ->and(companyDraftOpen($customerId)->answers())->toBe([]);
    })->with(['a file for a text request' => ['TEXT'], 'text for a file request' => ['FILE']]);

    it('takes exactly one of a text and a file', function () {
        $id = strtolower((string) Str::ulid());
        companyDraftAfterRejection([ApplicationRequest::add($id, RequestKind::Text, 'Something asked for', 1)]);

        expect(fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($id)))->toThrow(InvalidCompanyAttribute::class)
            ->and(fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($id, text: 'Both', path: B2BFixtures::pdf(), originalFilename: 'x.pdf')))->toThrow(InvalidCompanyAttribute::class);
    });

    it('removes a file answer, deleting its file', function () {
        $file = strtolower((string) Str::ulid());
        [$customerId] = companyDraftAfterRejection([ApplicationRequest::add($file, RequestKind::File, 'A bank letter', 1)]);
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($file, path: B2BFixtures::pdf(), originalFilename: 'bank-letter.pdf'));
        $mediaId = (string) companyDraftOpen($customerId)->answers()[$file]->mediaId;

        app(RemoveApplicationAnswerHandler::class)->handle(new RemoveApplicationAnswer($file));

        expect(companyDraftOpen($customerId)->answers())->toBe([])
            ->and(companyDraftMediaExists($mediaId))->toBeFalse();
    });

    it('refuses answering and removing an answer while suspended (scenario 9l, amendment 9(a))', function () {
        $text = strtolower((string) Str::ulid());
        [$customerId] = companyDraftAfterRejection([ApplicationRequest::add($text, RequestKind::Text, 'Who signs?', 1)]);
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($text, text: 'Sara Ali'));
        B2BFixtures::suspend(app(CompanyRepository::class)->forCustomer($customerId) ?? throw new LogicException);

        expect(fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($text, text: 'Someone else')))->toThrow(CompanySuspended::class)
            ->and(fn () => app(RemoveApplicationAnswerHandler::class)->handle(new RemoveApplicationAnswer($text)))->toThrow(CompanySuspended::class)
            ->and(companyDraftOpen($customerId)->answers()[$text]->text?->value)->toBe('Sara Ali');
    });
});

it('refuses a second open application at the database too, behind the account\'s lock (lesson 64)', function () {
    // The partial unique index is the backstop behind the account lock (B2BCompanyAccountTest shows
    // every use case takes it): a caller that skipped the lock and added a second open application
    // is refused as ApplicationAlreadyOpen, not a raw database error.
    $customerId = B2BFixtures::companyAccount();
    Fx::actAsCustomer($customerId);
    companyDraftStart();
    $applications = app(ApplicationRepository::class);

    expect(fn () => $applications->add(Application::draft($applications->nextId(), $customerId, null)))->toThrow(ApplicationAlreadyOpen::class);
});

it('records when each paper was uploaded (§1.4)', function () {
    $customerId = B2BFixtures::companyAccount();
    Fx::actAsCustomer($customerId);
    companyDraftStart();
    CarbonImmutable::setTestNow('2026-09-29 10:00:00');

    companyDraftAttach(companyDraftDocumentTypeIds()[0]);

    expect(array_values(companyDraftOpen($customerId)->documents())[0]->uploadedAt->format('Y-m-d H:i'))->toBe('2026-09-29 10:00');

    CarbonImmutable::setTestNow();
});

it('writes nothing of its own to the audit log when it starts, uploads, answers or removes (amendment 4)', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $requestId = strtolower((string) Str::ulid());
    B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($requestId, RequestKind::File, 'A bank letter', 1)]);
    Fx::actAsCustomer($customerId);
    $typeId = companyDraftDocumentTypeIds()[0];
    $levels = B2BFixtures::auditLevels();

    companyDraftStart();
    companyDraftAttach($typeId);
    app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($requestId, path: B2BFixtures::pdf(), originalFilename: 'bank.pdf'));
    app(RemoveApplicationDocumentHandler::class)->handle(new RemoveApplicationDocument($typeId));
    app(RemoveApplicationAnswerHandler::class)->handle(new RemoveApplicationAnswer($requestId));
    $actions = array_column($levels->getArrayCopy(), 0);

    // Platform keeps its own entries for the files — which also shows the recorder heard them.
    expect(array_values(array_filter($actions, static fn (string $action): bool => str_starts_with($action, 'b2b.'))))->toBe([])
        ->and($actions)->toContain('platform.media.uploaded');
});
