<?php

declare(strict_types=1);

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
use Modules\B2B\Application\Command\DiscardApplicationDraft\DiscardApplicationDraft;
use Modules\B2B\Application\Command\DiscardApplicationDraft\DiscardApplicationDraftHandler;
use Modules\B2B\Application\Command\RemoveApplicationAnswer\RemoveApplicationAnswer;
use Modules\B2B\Application\Command\RemoveApplicationAnswer\RemoveApplicationAnswerHandler;
use Modules\B2B\Application\Command\RemoveApplicationDocument\RemoveApplicationDocument;
use Modules\B2B\Application\Command\RemoveApplicationDocument\RemoveApplicationDocumentHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContact;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContactHandler;
use Modules\B2B\Application\Query\OpenMyApplicationFile\OpenMyApplicationFile;
use Modules\B2B\Application\Query\OpenMyApplicationFile\OpenMyApplicationFileHandler;
use Modules\B2B\Application\Query\ViewMyCompany\AccountStage;
use Modules\B2B\Application\Query\ViewMyCompany\MyCompanyView;
use Modules\B2B\Application\Query\ViewMyCompany\TypeOption;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompany;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompanyHandler;
use Modules\B2B\Domain\Exception\ApplicationFileNotFound;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 3b: who reaches the company's own side, the address, the account's own files, and what the
| account is shown (b2b.md §1.1, §1.4, §3.1, §4.3; amendments 4, 5 and 9).
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

/**
 * Every one of the company's own use cases, as the signed-in account runs it.
 *
 * @return array<string, array{0: Closure(): mixed}>
 */
function companyAccountUseCases(): array
{
    return [
        'starting a draft' => [fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft)],
        'saving it' => [fn () => app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['name' => 'Al Noor Trading']))],
        'uploading a paper' => [fn () => app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument(B2BFixtures::documentTypes()[0]->id(), B2BFixtures::pdf(), 'x.pdf'))],
        'removing a paper' => [fn () => app(RemoveApplicationDocumentHandler::class)->handle(new RemoveApplicationDocument(B2BFixtures::documentTypes()[0]->id()))],
        'answering a request' => [fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest(strtolower((string) Str::ulid()), text: 'An answer'))],
        'removing an answer' => [fn () => app(RemoveApplicationAnswerHandler::class)->handle(new RemoveApplicationAnswer(strtolower((string) Str::ulid())))],
        'sending' => [fn () => app(SubmitApplicationHandler::class)->handle(new SubmitApplication)],
        'discarding' => [fn () => app(DiscardApplicationDraftHandler::class)->handle(new DiscardApplicationDraft)],
        'opening a file' => [fn () => app(OpenMyApplicationFileHandler::class)->handle(new OpenMyApplicationFile(strtolower((string) Str::ulid())))],
        'viewing the company' => [fn () => app(ViewMyCompanyHandler::class)->handle(new ViewMyCompany)],
        'changing the address' => [fn () => app(UpdateCompanyContactHandler::class)->handle(new UpdateCompanyContact('Riyadh'))],
    ];
}

function companyAccountView(): MyCompanyView
{
    return app(ViewMyCompanyHandler::class)->handle(new ViewMyCompany);
}

function companyAccountMove(string $address): void
{
    app(UpdateCompanyContactHandler::class)->handle(new UpdateCompanyContact($address));
}

describe('who reaches the company\'s own side (§3.1)', function () {
    it('refuses an individual account in the handler itself (scenario 10)', function (Closure $useCase) {
        Fx::actAsCustomer(Fx::customer(strtolower((string) Str::ulid()).'@example.test'));
        $applications = DB::table('b2b.applications')->count();

        expect($useCase)->toThrow(NotACompanyAccount::class)
            ->and(DB::table('b2b.applications')->count())->toBe($applications);
    })->with(companyAccountUseCases());

    it('refuses an account Access cannot find the same way (amendment 9(b))', function (Closure $useCase) {
        Fx::actAsCustomer(strtolower((string) Str::ulid()));

        expect($useCase)->toThrow(NotACompanyAccount::class);
    })->with(companyAccountUseCases());

    it('refuses a staff member, even a Super Admin, by the permission they lack', function (Closure $useCase) {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect($useCase)->toThrow(Unauthorized::class);
    })->with(companyAccountUseCases());

    it('refuses the system, which holds every permission: only a signed-in customer has an account', function (Closure $useCase) {
        // The permission check lets the system through, so only CurrentCompanyAccount can refuse it
        // (lesson 40).
        expect(fn () => Fx::asSystem($useCase))->toThrow(Unauthorized::class);
    })->with(companyAccountUseCases());
});

describe('the address (§1.1, amendments 4 and 5)', function () {
    it('changes in every status, suspended included, and never sends the company back to PENDING', function (string $status) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);

        if ($status === 'SUSPENDED') {
            B2BFixtures::suspend($company);
        }

        Fx::actAsCustomer($customerId);

        companyAccountMove("Olaya Street\nRiyadh");
        $moved = app(CompanyRepository::class)->forCustomer($customerId);

        expect($moved?->details()->address->value)->toBe("Olaya Street\nRiyadh")
            ->and($moved?->status()->value)->toBe($status);
    })->with(['REJECTED', 'SUSPENDED']);

    it('is audited on the company, as "changed", inside its own transaction (amendment 4)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        $levels = B2BFixtures::auditLevels();

        companyAccountMove('Riyadh, Olaya');
        $entry = DB::table('platform.audit_entries')->where('action', 'b2b.company.address_changed')->first();

        expect($levels->getArrayCopy())->toBe([['b2b.company.address_changed', 2]])
            ->and($entry?->subject_type)->toBe('b2b.company')
            ->and($entry?->subject_id)->toBe($company->id())
            ->and($entry?->store_id)->toBe(Fx::storeId('sa'))
            ->and(json_decode((string) $entry?->changes, true))->toBe(['address' => 'changed']);
    });

    it('is written into the open draft too (amendment 5)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);

        companyAccountMove('Riyadh, Olaya');

        expect(app(ApplicationRepository::class)->openFor($customerId)?->address()?->value)->toBe('Riyadh, Olaya');
    });

    it('changes nothing, and writes nothing, when it is the same address', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        $levels = B2BFixtures::auditLevels();

        companyAccountMove("King Fahd Road\nRiyadh");

        expect($levels->getArrayCopy())->toBe([]);
    });

    it('refuses before there is a company, and an address that is not one', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);

        expect(fn () => companyAccountMove('Riyadh'))->toThrow(CompanyNotFound::class);

        B2BFixtures::rejected($customerId);

        expect(fn () => companyAccountMove('   '))->toThrow(InvalidCompanyAttribute::class)
            ->and(fn () => companyAccountMove(str_repeat('a', 501)))->toThrow(InvalidCompanyAttribute::class);
    });
});

describe('the account\'s own files (§1.4, amendments 5 and 9(c))', function () {
    it('opens a 30-minute link to a paper of one of its own applications, sent or in the draft', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [, $rejected] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        $sentFile = array_values($rejected->documents())[0]->mediaId;
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $typeId = B2BFixtures::documentTypes()[1]->id();
        app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($typeId, B2BFixtures::pdf(), 'new.pdf'));
        $draftFile = (string) app(ApplicationRepository::class)->openFor($customerId)?->documents()[$typeId]->mediaId;

        foreach ([$sentFile, $draftFile] as $mediaId) {
            $link = app(OpenMyApplicationFileHandler::class)->handle(new OpenMyApplicationFile($mediaId));

            expect($link->url)->not->toBe('')
                ->and($link->expiresAt->getTimestamp() - time())->toBeGreaterThan(29 * 60)->toBeLessThanOrEqual(30 * 60);
        }
    });

    it('opens a file answer too', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $requestId = strtolower((string) Str::ulid());
        B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($requestId, RequestKind::File, 'A bank letter', 1)]);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($requestId, path: B2BFixtures::pdf(), originalFilename: 'bank.pdf'));
        $mediaId = (string) app(ApplicationRepository::class)->openFor($customerId)?->answers()[$requestId]->mediaId;

        expect(app(OpenMyApplicationFileHandler::class)->handle(new OpenMyApplicationFile($mediaId))->url)->not->toBe('');
    });

    it('refuses another account\'s file exactly as one that does not exist', function () {
        $other = B2BFixtures::verifiedCompanyAccount();
        [, $theirs] = B2BFixtures::rejected($other);
        $theirFile = array_values($theirs->documents())[0]->mediaId;
        Fx::actAsCustomer(B2BFixtures::verifiedCompanyAccount());
        $error = static function (string $mediaId): ?Throwable {
            try {
                app(OpenMyApplicationFileHandler::class)->handle(new OpenMyApplicationFile($mediaId));
            } catch (Throwable $caught) {
                return $caught;
            }

            return null;
        };

        $forTheirs = $error($theirFile);
        $forNothing = $error(strtolower((string) Str::ulid()));

        expect($forTheirs)->toBeInstanceOf(ApplicationFileNotFound::class)
            ->and($forNothing)->toBeInstanceOf(ApplicationFileNotFound::class)
            ->and($forTheirs?->getMessage())->toBe($forNothing?->getMessage());
    });
});

describe('what the account is shown (§3.1, §4.3)', function () {
    it('tells an account which step it is on before there is a company (§4.3, scenario 1)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);

        $unconfirmed = companyAccountView();
        DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => now()]);
        $nothingStarted = companyAccountView();
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $started = companyAccountView();

        expect($unconfirmed->stage)->toBe(AccountStage::EmailNotConfirmed)
            // Scenario 1: confirmed, nothing started, so it is told to continue its application.
            ->and($nothingStarted->stage)->toBe(AccountStage::NoApplication)
            ->and($nothingStarted->company)->toBeNull()
            ->and($nothingStarted->draft)->toBeNull()
            ->and($started->stage)->toBe(AccountStage::DraftOpen)
            ->and($started->draft)->not->toBeNull();
    });

    it('shows the company, its status and reason, and its history newest first with no staff names', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company, $first] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['cr_number' => '1010999999']));
        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);

        $view = companyAccountView();

        expect($view->stage)->toBeNull()
            ->and($view->company?->id)->toBe($company->id())
            ->and($view->company?->status)->toBe('PENDING')
            ->and($view->company?->mayOrder)->toBeFalse()
            ->and($view->company?->details->crNumber)->toBe('1010999999')
            ->and($view->company?->details->companyTypeNameEn)->not->toBeNull()
            ->and(array_map(static fn ($sent): array => [$sent->state, $sent->values->crNumber], $view->history))->toBe([
                ['SUBMITTED', '1010999999'],
                ['REJECTED', '1010123456'],
            ])
            ->and($view->history[1]->id)->toBe($first->id())
            ->and($view->history[1]->decisionReason)->toBe('The CR number does not match the certificate.')
            ->and($view->history[1]->documents)->toHaveCount(count($first->documents()))
            // Nothing in it names who decided.
            ->and(json_encode($view))->not->toContain((string) $first->decidedBy());
    });

    it('shows the open draft with the last rejection\'s marks and requests, its answers, and what is no longer accepted', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $requestId = strtolower((string) Str::ulid());
        $documentType = B2BFixtures::documentTypes()[0];
        [, $rejected] = B2BFixtures::rejected(
            $customerId,
            [ApplicationFlag::field(FlaggedField::CrNumber), ApplicationFlag::document($documentType->id())],
            [ApplicationRequest::add($requestId, RequestKind::Text, 'Who signs for the company?', 1)],
        );
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($requestId, text: 'Sara Ali'));
        B2BFixtures::deactivate($documentType, InactiveTypeDisplay::Greyed);
        B2BFixtures::deactivate(B2BFixtures::companyTypes()[1]);

        $draft = companyAccountView()->draft;
        $files = [];

        foreach ($draft->documents ?? [] as $file) {
            $files[$file->documentTypeId] = $file->noLongerAccepted;
        }

        expect(array_map(static fn ($flag): array => [$flag->field, $flag->documentTypeId], $draft->flags ?? []))->toEqualCanonicalizing([['cr_number', null], [null, $documentType->id()]])
            ->and(array_map(static fn ($request): array => [$request->id, $request->kind, $request->label], $draft->requests ?? []))->toBe([[$requestId, 'TEXT', 'Who signs for the company?']])
            ->and(array_map(static fn ($answer): array => [$answer->requestId, $answer->text], $draft->answers ?? []))->toBe([[$requestId, 'Sara Ali']])
            // The paper under the type deactivated since is marked; the others are not.
            ->and($files[$documentType->id()] ?? null)->toBeTrue()
            ->and(array_values(array_filter($files)))->toBe([true])
            // The draft's type — the company's, the fixtures' second — was deactivated since.
            ->and($draft?->typeNoLongerAccepted)->toBeTrue()
            ->and(count($rejected->documents()))->toBe(count($files));
    });

    it('offers the home store\'s types in order: greyed ones marked, hidden ones left out, required papers marked (§1.3)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        [$hidden, $greyed] = B2BFixtures::companyTypes();
        B2BFixtures::deactivate($hidden);
        B2BFixtures::deactivate($greyed, InactiveTypeDisplay::Greyed);
        $greyedDocument = B2BFixtures::documentTypes()[2];
        B2BFixtures::deactivate($greyedDocument, InactiveTypeDisplay::Greyed);

        $view = companyAccountView();
        $ids = array_map(static fn (TypeOption $option): string => $option->id, $view->companyTypes);
        $expected = array_map(static fn ($type): string => $type->id(), array_slice(B2BFixtures::companyTypes(), 1));
        $documents = [];

        foreach ($view->documentTypes as $option) {
            $documents[$option->id] = [$option->greyed, $option->required];
        }

        expect($ids)->toBe($expected)
            ->and($ids)->not->toContain($hidden->id())
            ->and($view->companyTypes[0]->greyed)->toBeTrue()
            ->and($view->companyTypes[1]->greyed)->toBeFalse()
            ->and($documents[$greyedDocument->id()])->toBe([true, false])
            ->and(array_values(array_filter($documents, static fn (array $flags): bool => $flags === [false, true])))->toHaveCount(2)
            // Another store's lists are not offered.
            ->and($ids)->not->toContain(B2BFixtures::companyTypes('eg')[0]->id());
    });
});
