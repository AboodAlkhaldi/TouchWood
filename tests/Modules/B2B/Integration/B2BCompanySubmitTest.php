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
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\DiscardApplicationDraft\DiscardApplicationDraft;
use Modules\B2B\Application\Command\DiscardApplicationDraft\DiscardApplicationDraftHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\DocumentNoLongerAccepted;
use Modules\B2B\Domain\Exception\EmailNotVerified;
use Modules\B2B\Domain\Exception\FlaggedItemNotReplaced;
use Modules\B2B\Domain\Exception\MissingRequiredDocument;
use Modules\B2B\Domain\Exception\PhoneNotConfirmed;
use Modules\B2B\Domain\Exception\RequestNotAnswered;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 3b: sending an application and discarding a draft (b2b.md §1.2, §3.1, §4.1; amendments
| 3, 4 and 5). Only these two, and the address change, are audited.
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

function companySubmitSend(): void
{
    app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
}

function companySubmitDiscard(): void
{
    app(DiscardApplicationDraftHandler::class)->handle(new DiscardApplicationDraft);
}

/**
 * The signed-in account's draft, started and filled in completely: every value, a listed type, one
 * of its saved addresses, and a paper under every active document type, each its own file — or
 * under all but $leaveOut.
 */
function companySubmitFilledDraft(?string $leaveOut = null, ?string $typeId = null): void
{
    app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
    app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft([
        'name' => 'Al Noor Trading',
        'company_type_id' => $typeId ?? B2BFixtures::companyTypes()[0]->id(),
        'cr_number' => '1010123456',
        'tax_number' => '300123456700003',
        'address_id' => B2BFixtures::savedAddress((string) app(ActorContext::class)->current()->id),
    ]));

    foreach (B2BFixtures::documentTypes() as $type) {
        if ($type->isActive() && $type->id() !== $leaveOut) {
            app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($type->id(), B2BFixtures::pdf(), 'paper-'.$type->id().'.pdf'));
        }
    }
}

function companySubmitOpen(string $customerId): ?Application
{
    return app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('sa'));
}

function companySubmitCompany(string $customerId): ?Company
{
    return app(CompanyRepository::class)->forCustomer($customerId, Fx::storeId('sa'));
}

/**
 * The first application, sent and approved with its company — as staff will in step 4.
 */
function companySubmitApproved(string $customerId): Company
{
    [$company, $application] = B2BFixtures::sent($customerId);
    $staffId = Fx::staff();
    $application->approve($staffId, null, CarbonImmutable::now());
    app(ApplicationRepository::class)->update($application);
    $company->approve($staffId, CarbonImmutable::now());
    app(CompanyRepository::class)->update($company);

    return $company;
}

/**
 * The changes an audit entry recorded, in a steady key order (lesson 105).
 *
 * @return array<string, mixed>
 */
function companySubmitChanges(string $action, string $subjectId): array
{
    $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->value('changes'), true);
    $changes = is_array($changes) ? $changes : [];
    ksort($changes);

    return $changes;
}

describe('sending an application (§1.2, §3.1, §4.1)', function () {
    it('creates the company PENDING and marks the application sent (scenario 3)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);
        companySubmitFilledDraft();
        $draftId = companySubmitOpen($customerId)?->id();

        companySubmitSend();
        $company = companySubmitCompany($customerId);
        $sent = app(ApplicationRepository::class)->find((string) $draftId);

        expect($company?->status()->value)->toBe('PENDING')
            ->and($company?->homeStoreId())->toBe(Fx::storeId('sa'))
            ->and($company?->details()->name->value)->toBe('Al Noor Trading')
            ->and($company?->mayOrder())->toBeFalse()
            ->and($sent?->state()->value)->toBe('SUBMITTED')
            ->and($sent?->companyId())->toBe($company?->id())
            ->and($sent?->submittedAt())->not->toBeNull();
    });

    it('audits it on the application inside its own transaction: what was typed as "changed", the states and the listed type by value (amendment 4)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);
        $typeId = B2BFixtures::companyTypes()[0]->id();
        companySubmitFilledDraft(typeId: $typeId);
        $draftId = (string) companySubmitOpen($customerId)?->id();
        $levels = B2BFixtures::auditLevels();

        companySubmitSend();
        $entry = DB::table('platform.audit_entries')->where('action', 'b2b.application.submitted')->first();

        // Level 2: inside the handler's transaction, not only the test's (lesson 87).
        expect($levels->getArrayCopy())->toBe([['b2b.application.submitted', 2]])
            ->and($entry?->subject_type)->toBe('b2b.application')
            ->and($entry?->subject_id)->toBe($draftId)
            ->and($entry?->store_id)->toBe(Fx::storeId('sa'))
            ->and($entry?->actor_type)->toBe('CUSTOMER')
            ->and($entry?->actor_id)->toBe($customerId)
            ->and(companySubmitChanges('b2b.application.submitted', $draftId))->toBe([
                'address' => 'changed',
                'company_status' => [null, 'PENDING'],
                'company_type_id' => [null, $typeId],
                'cr_number' => 'changed',
                'name' => 'changed',
                'state' => ['DRAFT', 'SUBMITTED'],
                'tax_number' => 'changed',
            ]);
    });

    it('audits a type typed under Other, and a note, as "changed" — never by value (amendment 4, the review of step 3b)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);
        companySubmitFilledDraft();
        app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['company_type_other' => 'Charitable foundation', 'note' => 'Please call before visiting.']));
        $draftId = (string) companySubmitOpen($customerId)?->id();

        companySubmitSend();

        expect(companySubmitChanges('b2b.application.submitted', $draftId))->toBe([
            'address' => 'changed',
            'company_status' => [null, 'PENDING'],
            'company_type_other' => 'changed',
            'cr_number' => 'changed',
            'name' => 'changed',
            'note' => 'changed',
            'state' => ['DRAFT', 'SUBMITTED'],
            'tax_number' => 'changed',
        ]);
    });

    it('refuses before the email address is confirmed, and creates nothing (scenario 5)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companySubmitFilledDraft();

        expect(fn () => companySubmitSend())->toThrow(EmailNotVerified::class)
            ->and(companySubmitCompany($customerId))->toBeNull()
            ->and(companySubmitOpen($customerId)?->state()->value)->toBe('DRAFT');
    });

    it('refuses before the phone number is confirmed, creates nothing, and keeps the draft (amendment 26(a))', function () {
        $customerId = B2BFixtures::verifiedCompanyAccountWithoutPhone();
        Fx::actAsCustomer($customerId);
        // The form is filled and saved as ever: only Send waits for the phone.
        companySubmitFilledDraft();

        expect(fn () => companySubmitSend())->toThrow(PhoneNotConfirmed::class)
            ->and(companySubmitCompany($customerId))->toBeNull()
            ->and(companySubmitOpen($customerId)?->state()->value)->toBe('DRAFT');

        // Once the account's phone is confirmed - any country's - the same draft goes.
        DB::table('access.customers')->where('id', $customerId)->update(['phone' => '+201'.random_int(100_000_000, 999_999_999), 'phone_verified_at' => now()]);
        companySubmitSend();

        expect(companySubmitCompany($customerId)?->status()->value)->toBe('PENDING');
    });

    it('refuses without a required paper, and creates nothing (scenario 4)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);
        companySubmitFilledDraft(leaveOut: B2BFixtures::documentTypes()[0]->id());

        expect(fn () => companySubmitSend())->toThrow(MissingRequiredDocument::class)
            ->and(companySubmitCompany($customerId))->toBeNull()
            ->and(companySubmitOpen($customerId)?->state()->value)->toBe('DRAFT');
    });

    it('refuses a type deactivated since the draft chose it (scenario 9a)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);
        $type = B2BFixtures::companyTypes()[0];
        companySubmitFilledDraft(typeId: $type->id());
        B2BFixtures::deactivate($type);

        expect(fn () => companySubmitSend())->toThrow(CompanyTypeInactive::class)
            ->and(companySubmitCompany($customerId))->toBeNull();
    });

    it('refuses a paper under a type deactivated since (scenario 9i)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);
        companySubmitFilledDraft();
        B2BFixtures::deactivate(B2BFixtures::documentTypes()[0]);

        expect(fn () => companySubmitSend())->toThrow(DocumentNoLongerAccepted::class)
            ->and(companySubmitCompany($customerId))->toBeNull();
    });

    it('sends an approved company\'s new details, which keeps ordering until they are sent (scenario 9c)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        companySubmitApproved($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['name' => 'Al Noor Trading Group']));

        $whileUnsent = companySubmitCompany($customerId);
        companySubmitSend();
        $afterSending = companySubmitCompany($customerId);

        expect($whileUnsent?->status()->value)->toBe('APPROVED')
            ->and($whileUnsent?->mayOrder())->toBeTrue()
            ->and($afterSending?->status()->value)->toBe('PENDING')
            ->and($afterSending?->mayOrder())->toBeFalse()
            ->and($afterSending?->details()->name->value)->toBe('Al Noor Trading Group')
            ->and(companySubmitChanges('b2b.application.submitted', (string) companySubmitOpen($customerId)?->id())['company_status'] ?? null)->toBe(['APPROVED', 'PENDING']);
    });

    it('reapplies after a rejection: PENDING again, and the rejected application keeps what it sent (scenario 8)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [, $rejected] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['cr_number' => '1010999999']));

        companySubmitSend();

        expect(companySubmitCompany($customerId)?->status()->value)->toBe('PENDING')
            ->and(companySubmitCompany($customerId)?->details()->crNumber->value)->toBe('1010999999')
            ->and(app(ApplicationRepository::class)->find($rejected->id())?->crNumber()?->value)->toBe('1010123456')
            ->and(app(ApplicationRepository::class)->find($rejected->id())?->state()->value)->toBe('REJECTED');
    });

    it('refuses while a flagged field holds what was sent, or a request has no answer (scenario 9f)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::rejected($customerId, [ApplicationFlag::field(FlaggedField::CrNumber)], [ApplicationRequest::add(strtolower((string) Str::ulid()), RequestKind::Text, 'Who signs?', 1)]);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);

        expect(fn () => companySubmitSend())->toThrow(FlaggedItemNotReplaced::class);

        app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['cr_number' => '1010999999']));

        expect(fn () => companySubmitSend())->toThrow(RequestNotAnswered::class)
            ->and(companySubmitCompany($customerId)?->status()->value)->toBe('REJECTED');
    });

    it('refuses a suspended company, which stays suspended (scenario 11)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        B2BFixtures::suspend($company);

        expect(fn () => companySubmitSend())->toThrow(CompanySuspended::class)
            ->and(companySubmitCompany($customerId)?->status()->value)->toBe('SUSPENDED')
            ->and(companySubmitOpen($customerId)?->state()->value)->toBe('DRAFT');
    });

    it('refuses sending again what is already sent (scenario 6)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);
        companySubmitFilledDraft();
        companySubmitSend();

        expect(fn () => companySubmitSend())->toThrow(ApplicationNotEditable::class)
            ->and(DB::table('b2b.companies')->where('customer_id', $customerId)->count())->toBe(1);
    });

    it('refuses with no draft open', function () {
        Fx::actAsCustomer(B2BFixtures::verifiedCompanyAccount());

        expect(fn () => companySubmitSend())->toThrow(ApplicationNotFound::class);
    });
});

describe('discarding a draft (§1.2, amendments 3 and 5)', function () {
    it('throws a first draft away with the files it held, audited on the application (scenario 9d)', function () {
        $customerId = B2BFixtures::companyAccount();
        Fx::actAsCustomer($customerId);
        companySubmitFilledDraft();
        $draft = companySubmitOpen($customerId);
        $files = array_values(array_map(static fn ($document): string => $document->mediaId, $draft?->documents() ?? []));
        $levels = B2BFixtures::auditLevels();

        companySubmitDiscard();

        expect(companySubmitOpen($customerId))->toBeNull()
            ->and($files)->not->toBe([])
            ->and(DB::table('platform.media')->whereIn('id', $files)->count())->toBe(0)
            ->and($levels->getArrayCopy())->toContain(['b2b.application.discarded', 2])
            ->and(companySubmitChanges('b2b.application.discarded', (string) $draft?->id()))->toBe(['state' => ['DRAFT', null]]);
    });

    it('keeps the files the last application sent still holds (scenario 9d)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [, $rejected] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $typeId = B2BFixtures::documentTypes()[0]->id();
        app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($typeId, B2BFixtures::pdf(), 'new.pdf'));
        $new = (string) companySubmitOpen($customerId)?->documents()[$typeId]->mediaId;

        companySubmitDiscard();

        $carried = array_values(array_map(static fn ($document): string => $document->mediaId, $rejected->documents()));

        expect(DB::table('platform.media')->whereIn('id', $carried)->count())->toBe(count($carried))
            ->and(DB::table('platform.media')->where('id', $new)->exists())->toBeFalse();
    });

    it('is allowed while the company is suspended (scenario 9l)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        B2BFixtures::suspend($company);

        companySubmitDiscard();

        expect(companySubmitOpen($customerId))->toBeNull()
            ->and(companySubmitCompany($customerId)?->status()->value)->toBe('SUSPENDED');
    });

    it('leaves an approved company approved, still able to order (§1.1)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        companySubmitApproved($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['name' => 'Al Noor Trading Group']));

        companySubmitDiscard();

        expect(companySubmitCompany($customerId)?->status()->value)->toBe('APPROVED')
            ->and(companySubmitCompany($customerId)?->mayOrder())->toBeTrue()
            ->and(companySubmitCompany($customerId)?->details()->name->value)->toBe('Al Noor Trading');
    });

    it('refuses a sent application, and when there is no draft', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);

        expect(fn () => companySubmitDiscard())->toThrow(ApplicationNotFound::class);

        B2BFixtures::sent($customerId);

        expect(fn () => companySubmitDiscard())->toThrow(ApplicationNotEditable::class)
            ->and(companySubmitOpen($customerId)?->state()->value)->toBe('SUBMITTED');
    });
});
