<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\ApproveCompany\ApprovalTypeChoice;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompany;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompanyHandler;
use Modules\B2B\Application\Command\CorrectCompanyType\CorrectCompanyType;
use Modules\B2B\Application\Command\CorrectCompanyType\CorrectCompanyTypeHandler;
use Modules\B2B\Application\Command\DownloadCompanyDocument\DownloadCompanyDocument;
use Modules\B2B\Application\Command\DownloadCompanyDocument\DownloadCompanyDocumentHandler;
use Modules\B2B\Application\Command\ReinstateCompany\ReinstateCompany;
use Modules\B2B\Application\Command\ReinstateCompany\ReinstateCompanyHandler;
use Modules\B2B\Application\Command\RejectCompany\RejectCompany;
use Modules\B2B\Application\Command\RejectCompany\RejectCompanyHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SuspendCompany\SuspendCompany;
use Modules\B2B\Application\Command\SuspendCompany\SuspendCompanyHandler;
use Modules\B2B\Application\Query\ViewCompany\ViewCompany;
use Modules\B2B\Application\Query\ViewCompany\ViewCompanyHandler;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompany;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompanyHandler;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\CompanyTypeChoiceNotNeeded;
use Modules\B2B\Domain\Exception\CompanyTypeChoiceRequired;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Public\Enums\CompanyStatus;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 4: staff deciding on a company (b2b.md §3.2, §4.1; amendments 1, 4 and 10) — who may,
| approving with its type choice, rejecting with flags and requests, suspending and reinstating,
| correcting the type, the emails and the audit log.
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
 * A staff member of the 'sa' store holding these jobs (every B2B job unless named), signed in.
 *
 * @param  list<string>|null  $permissions
 * @param  list<string>  $stores
 */
function staffDecisionsReviewer(?array $permissions = null, array $stores = ['sa']): string
{
    $staffId = Fx::staffWith($permissions ?? B2BPermissions::staff(), $stores);
    Fx::actAsStaff($staffId);

    return $staffId;
}

/**
 * A company whose first application was just sent: PENDING, waiting for a decision.
 *
 * @return array{0: string, 1: Company}
 */
function staffDecisionsPending(): array
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::sent($customerId);

    return [$customerId, $company];
}

function staffDecisionsCompany(string $customerId): Company
{
    return app(CompanyRepository::class)->forCustomer($customerId) ?? throw new LogicException('No company.');
}

/**
 * @return array<string, mixed> the changes the newest such audit entry recorded, in a steady key
 *                              order (lesson 105)
 */
function staffDecisionsChanges(string $action, string $subjectId): array
{
    $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->orderByDesc('id')->value('changes'), true);
    $changes = is_array($changes) ? $changes : [];
    ksort($changes);

    return $changes;
}

/**
 * @return list<array{to: string, decision: string, text: ?string, locale: string}>
 */
function staffDecisionsMails(): array
{
    return RecordingSecurityMessages::installed()->companyDecisions;
}

function staffDecisionsApprove(string $companyId, ?string $note = null, ?ApprovalTypeChoice $choice = null, ?string $correctTypeId = null, bool $confirm = false): void
{
    app(ApproveCompanyHandler::class)->handle(new ApproveCompany($companyId, $note, $choice, $correctTypeId, confirmReactivation: $confirm));
}

/**
 * @param  list<string>  $fields
 * @param  list<string>  $documents
 * @param  list<array{kind: string, label: string}>  $requests
 */
function staffDecisionsReject(string $companyId, string $reason = 'The CR number does not match the certificate.', array $fields = [], array $documents = [], array $requests = []): void
{
    app(RejectCompanyHandler::class)->handle(new RejectCompany($companyId, $reason, $fields, $documents, $requests));
}

function staffDecisionsSuspend(string $companyId, string $reason = 'A bank transfer was reversed.'): void
{
    app(SuspendCompanyHandler::class)->handle(new SuspendCompany($companyId, $reason));
}

function staffDecisionsReinstate(string $companyId, string $reason = 'The transfer was settled.'): void
{
    app(ReinstateCompanyHandler::class)->handle(new ReinstateCompany($companyId, $reason));
}

function staffDecisionsCorrect(string $companyId, ?string $typeId = null, ?string $other = null, bool $confirm = false): void
{
    app(CorrectCompanyTypeHandler::class)->handle(new CorrectCompanyType($companyId, $typeId, $other, $confirm));
}

/**
 * Every staff use case on one company, as the signed-in actor runs it.
 *
 * @return array<string, array{0: Closure(string, string): mixed}>
 */
function staffDecisionsUseCases(): array
{
    return [
        'viewing' => [fn (string $companyId) => app(ViewCompanyHandler::class)->handle(new ViewCompany($companyId))],
        'opening a paper' => [fn (string $companyId, string $mediaId) => app(DownloadCompanyDocumentHandler::class)->handle(new DownloadCompanyDocument($companyId, $mediaId))],
        'approving' => [fn (string $companyId) => staffDecisionsApprove($companyId)],
        'rejecting' => [fn (string $companyId) => staffDecisionsReject($companyId)],
        'suspending' => [fn (string $companyId) => staffDecisionsSuspend($companyId)],
        'reinstating' => [fn (string $companyId) => staffDecisionsReinstate($companyId)],
        'correcting the type' => [fn (string $companyId) => staffDecisionsCorrect($companyId, other: 'Cooperative society')],
    ];
}

/**
 * The use cases a decision is recorded against a staff member for (decided_by, status_changed_by).
 *
 * @return array<string, array{0: Closure(string, string): mixed}>
 */
function staffDecisionsDecisions(): array
{
    return array_intersect_key(staffDecisionsUseCases(), array_flip(['approving', 'rejecting', 'suspending', 'reinstating']));
}

describe('who may act on a company (§3.2, amendment 10)', function () {
    it('refuses someone who holds the job in no store, by its name, before reading anything', function (Closure $useCase) {
        [, $company] = staffDecisionsPending();
        staffDecisionsReviewer(['access.customer.view']);

        expect(fn () => $useCase($company->id(), strtolower((string) Str::ulid())))->toThrow(Unauthorized::class);
    })->with(staffDecisionsUseCases());

    it('answers staff of another store exactly as for a company that does not exist (scenario 15)', function (Closure $useCase) {
        [, $company] = staffDecisionsPending();
        staffDecisionsReviewer(stores: ['eg']);
        $message = static function (string $companyId) use ($useCase): ?string {
            try {
                $useCase($companyId, strtolower((string) Str::ulid()));
            } catch (CompanyNotFound $caught) {
                return $caught->getMessage();
            }

            return null;
        };

        $forTheirs = $message($company->id());

        expect($forTheirs)->not->toBeNull()
            ->and($forTheirs)->toBe($message(strtolower((string) Str::ulid())))
            ->and(staffDecisionsCompany($company->customerId())->status())->toBe(CompanyStatus::Pending);
    })->with(staffDecisionsUseCases());

    it('refuses a customer, even the company\'s own account', function (Closure $useCase) {
        [$customerId, $company] = staffDecisionsPending();
        Fx::actAsCustomer($customerId);

        expect(fn () => $useCase($company->id(), strtolower((string) Str::ulid())))->toThrow(Unauthorized::class);
    })->with(staffDecisionsUseCases());

    it('refuses the system a decision: one is recorded against a staff member', function (Closure $useCase) {
        [, $company] = staffDecisionsPending();

        expect(fn () => Fx::asSystem(fn () => $useCase($company->id(), '')))->toThrow(Unauthorized::class);
    })->with(staffDecisionsDecisions());

    it('lets a Super Admin act in every store (scenario 15)', function () {
        [$customerId, $company] = staffDecisionsPending();
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        staffDecisionsApprove($company->id());

        expect(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Approved);
    });

    it('grants one job, never another (scenario 20)', function () {
        [$customerId, $company] = staffDecisionsPending();
        $mediaId = array_values(app(ApplicationRepository::class)->historyOf($company->id())[0]->documents())[0]->mediaId;
        staffDecisionsReviewer([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW]);

        expect(fn () => staffDecisionsSuspend($company->id()))->toThrow(Unauthorized::class)
            ->and(fn () => app(DownloadCompanyDocumentHandler::class)->handle(new DownloadCompanyDocument($company->id(), $mediaId)))->toThrow(Unauthorized::class)
            ->and(fn () => staffDecisionsCorrect($company->id(), other: 'Cooperative society'))->toThrow(Unauthorized::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending);
    });
});

describe('approving (§3.2, §4.1, amendments 1 and 10)', function () {
    it('lets the company order, records the reviewer, audits it on the application inside its own transaction, and emails the note', function () {
        [$customerId, $company] = staffDecisionsPending();
        $staffId = staffDecisionsReviewer();
        $applicationId = app(ApplicationRepository::class)->historyOf($company->id())[0]->id();
        $levels = B2BFixtures::auditLevels();

        staffDecisionsApprove($company->id(), 'Welcome aboard.');
        $approved = staffDecisionsCompany($customerId);
        $decided = app(ApplicationRepository::class)->find($applicationId);

        expect($approved->status())->toBe(CompanyStatus::Approved)
            ->and($approved->mayOrder())->toBeTrue()
            ->and($approved->statusChangedBy())->toBe($staffId)
            ->and($decided?->state()->value)->toBe('APPROVED')
            ->and($decided?->decidedBy())->toBe($staffId)
            ->and($decided?->decisionReason()?->value)->toBe('Welcome aboard.')
            ->and($levels->getArrayCopy())->toBe([['b2b.application.approved', 2]])
            ->and(staffDecisionsChanges('b2b.application.approved', $applicationId))->toBe([
                'company_status' => ['PENDING', 'APPROVED'],
                'note' => [null, 'Welcome aboard.'],
                'state' => ['SUBMITTED', 'APPROVED'],
            ])
            ->and(array_map(static fn (array $mail): array => [$mail['to'], $mail['decision'], $mail['text']], staffDecisionsMails()))
            ->toBe([[(string) DB::table('access.customers')->where('id', $customerId)->value('email'), 'approved', 'Welcome aboard.']]);
    });

    it('approves without a note: nothing is added to the email or the log', function () {
        [, $company] = staffDecisionsPending();
        staffDecisionsReviewer();

        staffDecisionsApprove($company->id(), '   ');
        $applicationId = app(ApplicationRepository::class)->historyOf($company->id())[0]->id();

        expect(staffDecisionsChanges('b2b.application.approved', $applicationId))->not->toHaveKey('note')
            ->and(array_column(staffDecisionsMails(), 'text'))->toBe([null]);
    });

    it('emails nobody until the approval has committed, and nobody when it rolls back', function () {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();

        expect(fn () => DB::transaction(function () use ($company): void {
            staffDecisionsApprove($company->id());

            throw new RuntimeException('Something after it failed.');
        }))->toThrow(RuntimeException::class);

        expect(staffDecisionsMails())->toBe([])
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending);
    });

    it('refuses a company with nothing waiting, and writes and sends nothing (§4.1, scenario 12a)', function (Closure $state) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $company = $state($customerId);
        staffDecisionsReviewer();
        $levels = B2BFixtures::auditLevels();

        expect(fn () => staffDecisionsApprove($company->id()))->toThrow(InvalidCompanyStatus::class)
            ->and(fn () => staffDecisionsReject($company->id()))->toThrow(InvalidCompanyStatus::class)
            ->and($levels->getArrayCopy())->toBe([])
            ->and(staffDecisionsMails())->toBe([]);
    })->with([
        'approved' => [fn (string $customerId) => B2BFixtures::approved($customerId)[0]],
        'rejected' => [fn (string $customerId) => B2BFixtures::rejected($customerId)[0]],
        'suspended while waiting' => [function (string $customerId) {
            [$company] = B2BFixtures::sent($customerId);
            B2BFixtures::suspend($company);

            return $company;
        }],
    ]);
});

describe('approving an application whose type was deactivated since it was sent (§1.3, amendment 10(e), (i))', function () {
    it('refuses to approve without a choice, and writes nothing', function () {
        [$customerId, $company] = staffDecisionsPending();
        B2BFixtures::deactivate(B2BFixtures::companyTypes()[1]);
        staffDecisionsReviewer();

        expect(fn () => staffDecisionsApprove($company->id()))->toThrow(CompanyTypeChoiceRequired::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending);
    });

    it('keeps the sent type for this company alone, which stays deactivated for everyone else', function () {
        [$customerId, $company] = staffDecisionsPending();
        $sent = B2BFixtures::companyTypes()[1];
        B2BFixtures::deactivate($sent);
        staffDecisionsReviewer();

        staffDecisionsApprove($company->id(), choice: ApprovalTypeChoice::Keep);

        expect(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Approved)
            ->and(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe($sent->id())
            ->and(app(CompanyTypeRepository::class)->find($sent->id())?->isActive())->toBeFalse();
    });

    it('uses the replacement the deactivation gave the company', function () {
        [$customerId, $company] = staffDecisionsPending();
        [$replacement, $sent] = B2BFixtures::companyTypes();
        // What replacing a deactivated type on its holders does (§1.3).
        $company->correctType(CompanyTypeChoice::listed($replacement->id()), B2BFixtures::companyTypes());
        app(CompanyRepository::class)->update($company);
        B2BFixtures::deactivate($sent);
        staffDecisionsReviewer();

        staffDecisionsApprove($company->id(), choice: ApprovalTypeChoice::Replacement);
        $applicationId = app(ApplicationRepository::class)->historyOf($company->id())[0]->id();

        expect(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe($replacement->id())
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Approved)
            ->and(staffDecisionsChanges('b2b.application.approved', $applicationId)['type_choice'] ?? null)->toBe([null, 'REPLACEMENT']);
    });

    it('refuses "the replacement" when the companies were left with the old type', function () {
        [$customerId, $company] = staffDecisionsPending();
        B2BFixtures::deactivate(B2BFixtures::companyTypes()[1]);
        staffDecisionsReviewer();

        expect(fn () => staffDecisionsApprove($company->id(), choice: ApprovalTypeChoice::Replacement))->toThrow(InvalidCompanyAttribute::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending);
    });

    it('corrects the type while approving, which needs the correction job as well', function () {
        [$customerId, $company] = staffDecisionsPending();
        [$other, $sent] = B2BFixtures::companyTypes();
        B2BFixtures::deactivate($sent);
        staffDecisionsReviewer([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW]);

        expect(fn () => staffDecisionsApprove($company->id(), choice: ApprovalTypeChoice::Correct, correctTypeId: $other->id()))->toThrow(Unauthorized::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending);

        staffDecisionsReviewer([B2BPermissions::COMPANY_REVIEW, B2BPermissions::COMPANY_CORRECT_TYPE]);
        staffDecisionsApprove($company->id(), choice: ApprovalTypeChoice::Correct, correctTypeId: $other->id());

        expect(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe($other->id())
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Approved);
    });

    it('refuses a choice when the type is active again, so the reviewer looks again (10(i))', function () {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();

        expect(fn () => staffDecisionsApprove($company->id(), choice: ApprovalTypeChoice::Keep))->toThrow(CompanyTypeChoiceNotNeeded::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending);
    });
});

describe('rejecting (§1.2, §3.2, amendment 4)', function () {
    it('rejects with a reason, flags and requests, audited by value on the application, and emails the reason', function () {
        [$customerId, $company] = staffDecisionsPending();
        $staffId = staffDecisionsReviewer();
        $application = app(ApplicationRepository::class)->historyOf($company->id())[0];
        $document = array_key_first($application->documents());
        $levels = B2BFixtures::auditLevels();

        staffDecisionsReject($company->id(), 'The CR number does not match the certificate.', ['cr_number'], [(string) $document], [
            ['kind' => 'TEXT', 'label' => 'Who signs for the company?'],
            ['kind' => 'file', 'label' => 'A bank letter'],
        ]);
        $rejected = app(ApplicationRepository::class)->find($application->id());

        expect(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Rejected)
            ->and(staffDecisionsCompany($customerId)->statusReason()?->value)->toBe('The CR number does not match the certificate.')
            ->and($rejected?->decidedBy())->toBe($staffId)
            ->and(array_map(static fn ($flag): string => $flag->key(), $rejected?->flags() ?? []))->toEqualCanonicalizing(['field:cr_number', 'document:'.$document])
            ->and(array_map(static fn ($request): array => [$request->kind->value, $request->label, $request->position], $rejected?->requests() ?? []))
            ->toBe([['TEXT', 'Who signs for the company?', 0], ['FILE', 'A bank letter', 1]])
            ->and($levels->getArrayCopy())->toBe([['b2b.application.rejected', 2]]);

        $changes = staffDecisionsChanges('b2b.application.rejected', $application->id());
        $flags = $changes['flags'][1] ?? [];
        sort($flags);

        expect(array_diff_key($changes, ['flags' => true]))->toBe([
            'company_status' => ['PENDING', 'REJECTED'],
            'reason' => [null, 'The CR number does not match the certificate.'],
            'requests' => [null, ['TEXT: Who signs for the company?', 'FILE: A bank letter']],
            'state' => ['SUBMITTED', 'REJECTED'],
        ])
            ->and($flags)->toBe(['document:'.$document, 'field:cr_number'])
            ->and(array_map(static fn (array $mail): array => [$mail['decision'], $mail['text']], staffDecisionsMails()))
            ->toBe([['rejected', 'The CR number does not match the certificate.']]);
    });

    it('puts the flags and requests in front of the company on its next draft (step 3 meets step 4)', function () {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();
        staffDecisionsReject($company->id(), fields: ['tax_number'], requests: [['kind' => 'TEXT', 'label' => 'Who signs?']]);

        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $draft = app(ViewMyCompanyHandler::class)->handle(new ViewMyCompany)->draft;

        expect(array_map(static fn ($flag): ?string => $flag->field, $draft->flags ?? []))->toBe(['tax_number'])
            ->and(array_map(static fn ($request): string => $request->label, $draft->requests ?? []))->toBe(['Who signs?']);
    });

    it('refuses what the rules refuse, and writes and sends nothing', function (Closure $reject) {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();

        expect(fn () => $reject($company->id()))->toThrow(InvalidCompanyAttribute::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending)
            ->and(staffDecisionsMails())->toBe([]);
    })->with([
        'no reason (scenario 16)' => [fn (string $companyId) => staffDecisionsReject($companyId, '  ')],
        'a field that cannot be flagged' => [fn (string $companyId) => staffDecisionsReject($companyId, fields: ['note'])],
        'a document the application did not send' => [fn (string $companyId) => staffDecisionsReject($companyId, documents: [strtolower((string) Str::ulid())])],
        'a request that is neither a text nor a file' => [fn (string $companyId) => staffDecisionsReject($companyId, requests: [['kind' => 'PHOTO', 'label' => 'A photo']])],
        'a request with no label' => [fn (string $companyId) => staffDecisionsReject($companyId, requests: [['kind' => 'TEXT', 'label' => ' ']])],
        'a request label too long' => [fn (string $companyId) => staffDecisionsReject($companyId, requests: [['kind' => 'TEXT', 'label' => str_repeat('a', 201)]])],
    ]);
});

describe('suspending and reinstating (§3.2, §4.1)', function () {
    it('suspends from any status and reinstates to it, never simply PENDING, audited on the company; only the suspension is emailed (scenario 12)', function (Closure $state, CompanyStatus $was) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $company = $state($customerId);
        $staffId = staffDecisionsReviewer();

        staffDecisionsSuspend($company->id(), 'A bank transfer was reversed.');
        $suspended = staffDecisionsCompany($customerId);

        expect($suspended->status())->toBe(CompanyStatus::Suspended)
            ->and($suspended->statusBeforeSuspension())->toBe($was)
            ->and($suspended->statusChangedBy())->toBe($staffId)
            ->and($suspended->mayOrder())->toBeFalse()
            ->and(staffDecisionsChanges('b2b.company.suspended', $company->id()))->toBe([
                'reason' => [null, 'A bank transfer was reversed.'],
                'status' => [$was->value, 'SUSPENDED'],
            ]);

        staffDecisionsReinstate($company->id(), 'The transfer was settled.');

        expect(staffDecisionsCompany($customerId)->status())->toBe($was)
            ->and(staffDecisionsCompany($customerId)->statusReason()?->value)->toBe('The transfer was settled.')
            ->and(staffDecisionsChanges('b2b.company.reinstated', $company->id()))->toBe([
                'reason' => [null, 'The transfer was settled.'],
                'status' => ['SUSPENDED', $was->value],
            ])
            ->and(array_map(static fn (array $mail): array => [$mail['decision'], $mail['text']], staffDecisionsMails()))
            ->toBe([['suspended', 'A bank transfer was reversed.']]);
    })->with([
        'pending' => [fn (string $customerId) => B2BFixtures::sent($customerId)[0], CompanyStatus::Pending],
        'approved' => [fn (string $customerId) => B2BFixtures::approved($customerId)[0], CompanyStatus::Approved],
        'rejected' => [fn (string $customerId) => B2BFixtures::rejected($customerId)[0], CompanyStatus::Rejected],
    ]);

    it('refuses suspending twice and reinstating what is not suspended', function () {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();

        expect(fn () => staffDecisionsReinstate($company->id()))->toThrow(InvalidCompanyStatus::class);

        staffDecisionsSuspend($company->id());

        expect(fn () => staffDecisionsSuspend($company->id()))->toThrow(InvalidCompanyStatus::class)
            ->and(staffDecisionsCompany($customerId)->statusBeforeSuspension())->toBe(CompanyStatus::Pending);
    });

    it('refuses a suspension or a reinstatement without a reason (scenario 16)', function () {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();

        expect(fn () => staffDecisionsSuspend($company->id(), ' '))->toThrow(InvalidCompanyAttribute::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Pending);

        staffDecisionsSuspend($company->id());

        expect(fn () => staffDecisionsReinstate($company->id(), ''))->toThrow(InvalidCompanyAttribute::class)
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Suspended);
    });

    it('decides a waiting application only once the company is reinstated', function () {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();
        staffDecisionsSuspend($company->id());

        expect(fn () => staffDecisionsApprove($company->id()))->toThrow(InvalidCompanyStatus::class);

        staffDecisionsReinstate($company->id());
        staffDecisionsApprove($company->id());

        expect(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Approved);
    });
});

describe('correcting the type (§3.2, amendments 2, 8(b) and 10)', function () {
    it('rewrites an "Other", or moves the company to a listed type, without sending it back to PENDING', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        $sent = B2BFixtures::companyTypes()[1]->id();
        $listed = B2BFixtures::companyTypes()[0]->id();
        staffDecisionsReviewer();

        staffDecisionsCorrect($company->id(), other: 'Cooperative society');

        expect(staffDecisionsCompany($customerId)->details()->type->other)->toBe('Cooperative society')
            ->and(staffDecisionsCompany($customerId)->status())->toBe(CompanyStatus::Rejected)
            ->and(staffDecisionsChanges('b2b.company.type_corrected', $company->id()))->toBe([
                'company_type_id' => [$sent, null],
                'company_type_other' => 'changed',
            ]);

        staffDecisionsCorrect($company->id(), $listed);

        expect(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe($listed)
            ->and(staffDecisionsChanges('b2b.company.type_corrected', $company->id()))->toBe([
                'company_type_id' => [null, $listed],
                'company_type_other' => 'changed',
            ]);
    });

    it('refuses a type of another store, and anything but exactly one of a type or "Other"', function (Closure $correct) {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();

        expect(fn () => $correct($company->id()))->toThrow(InvalidCompanyAttribute::class)
            ->and(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe(B2BFixtures::companyTypes()[1]->id());
    })->with([
        'another store\'s type' => [fn (string $companyId) => staffDecisionsCorrect($companyId, B2BFixtures::companyTypes('eg')[0]->id())],
        'neither' => [fn (string $companyId) => staffDecisionsCorrect($companyId)],
        'both' => [fn (string $companyId) => staffDecisionsCorrect($companyId, B2BFixtures::companyTypes()[0]->id(), 'Cooperative society')],
    ]);

    it('refuses to change a suspended company\'s type, or its draft (amendment 10(h), scenario 25)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        B2BFixtures::suspend($company);
        $sent = B2BFixtures::companyTypes()[1]->id();
        staffDecisionsReviewer();

        expect(fn () => staffDecisionsCorrect($company->id(), B2BFixtures::companyTypes()[0]->id()))->toThrow(CompanySuspended::class)
            ->and(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe($sent)
            ->and(app(ApplicationRepository::class)->openFor($customerId)?->type()?->typeId)->toBe($sent);
    });

    it('asks before taking a deactivated type, needs the job that activates types, and then activates it for the store (8(b), 10(b), scenario 22)', function () {
        [$customerId, $company] = staffDecisionsPending();
        $inactive = B2BFixtures::companyTypes()[2];
        B2BFixtures::deactivate($inactive);
        staffDecisionsReviewer([B2BPermissions::COMPANY_CORRECT_TYPE]);

        expect(fn () => staffDecisionsCorrect($company->id(), $inactive->id()))->toThrow(CompanyTypeInactive::class)
            ->and(fn () => staffDecisionsCorrect($company->id(), $inactive->id(), confirm: true))->toThrow(Unauthorized::class)
            ->and(app(CompanyTypeRepository::class)->find($inactive->id())?->isActive())->toBeFalse()
            ->and(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe(B2BFixtures::companyTypes()[1]->id());

        staffDecisionsReviewer([B2BPermissions::COMPANY_CORRECT_TYPE, B2BPermissions::COMPANY_TYPE_DEACTIVATE]);
        staffDecisionsCorrect($company->id(), $inactive->id(), confirm: true);

        expect(app(CompanyTypeRepository::class)->find($inactive->id())?->isActive())->toBeTrue()
            ->and(staffDecisionsCompany($customerId)->details()->type->typeId)->toBe($inactive->id())
            ->and(Fx::audits('b2b.company_type.activated', $inactive->id()))->toBe(1)
            // Activating a type is a change to the store's list: the "copied" notice goes (10(d)).
            ->and(DB::table('b2b.store_type_lists')->where('store_id', Fx::storeId('sa'))->value('copied_not_reviewed'))->toBeFalse();
    });

    it('carries the correction into an open draft only while the draft still holds the company\'s type (§3.1)', function (bool $draftChoseAnother) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $theirs = B2BFixtures::companyTypes()[3]->id();

        if ($draftChoseAnother) {
            app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['company_type_id' => $theirs]));
        }

        staffDecisionsReviewer();
        $corrected = B2BFixtures::companyTypes()[0]->id();
        staffDecisionsCorrect($company->id(), $corrected);

        expect(app(ApplicationRepository::class)->openFor($customerId)?->type()?->typeId)->toBe($draftChoseAnother ? $theirs : $corrected);
    })->with(['the draft still holds the company\'s type' => [false], 'the draft chose another' => [true]]);
});

describe('the locks (lesson 37)', function () {
    it('takes the account\'s lock, inside the use case\'s own transaction, for every decision', function (Closure $useCase) {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();
        $locks = B2BFixtures::accountLocks();

        try {
            $useCase($company->id(), '');
        } catch (Throwable) {
            // Refused after the lock, some of them — reinstating a company that is not suspended.
        }

        expect($locks->getArrayCopy())->toContain(['exclusive', 'b2b:account:'.$customerId, 2]);
    })->with(array_diff_key(staffDecisionsUseCases(), ['viewing' => true, 'opening a paper' => true]));

    it('takes the store\'s type lock before the account\'s when a correction may activate a type — B2B\'s one lock order', function () {
        [$customerId, $company] = staffDecisionsPending();
        staffDecisionsReviewer();
        $locks = B2BFixtures::accountLocks();

        staffDecisionsCorrect($company->id(), B2BFixtures::companyTypes()[0]->id());
        $keys = array_column($locks->getArrayCopy(), 1);

        expect($keys)->toBe(['b2b:types:'.Fx::storeId('sa'), 'b2b:account:'.$customerId]);
    });
});
