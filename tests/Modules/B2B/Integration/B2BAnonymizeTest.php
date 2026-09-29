<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccounts;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccountsHandler;
use Modules\B2B\Application\Account\CompanyAnonymizer;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequest;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequestHandler;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompany;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompanyHandler;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\B2B\Infrastructure\Listener\AnonymizeCompany;
use Modules\B2B\Public\Enums\CompanyStatus;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 5: what anonymizing an account reaches (b2b.md §1.1, §6, amendment 12(a), scenario 18).
| The company is never deleted; its name, numbers and address are, on it and on every application it
| sent, with their notes, answers and papers; an unsent draft goes whole.
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
 * A company with a whole history: a first application rejected with two requests, a second that
 * answered them — a file and a text — with its own note, approved with staff's note, and a third
 * still a draft, holding one new paper only it holds.
 *
 * @return array{customerId: string, company: Company, sent: list<string>, draftId: string, answerFile: string, draftFile: string}
 */
function companyAnonymizeHistory(): array
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $fileRequest = strtolower((string) Str::ulid());
    $textRequest = strtolower((string) Str::ulid());
    [$company, $first] = B2BFixtures::rejected($customerId, [], [
        ApplicationRequest::add($fileRequest, RequestKind::File, 'A bank letter', 0),
        ApplicationRequest::add($textRequest, RequestKind::Text, 'Who signs for the company?', 1),
    ]);

    Fx::actAsCustomer($customerId);
    app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
    app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['note' => 'Please call before noon.']));
    app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($fileRequest, path: B2BFixtures::pdf(), originalFilename: 'bank-letter.pdf'));
    app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($textRequest, text: 'The owner, in person.'));
    app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
    $second = app(ApplicationRepository::class)->lastSent($company->id()) ?? throw new LogicException('The second was not sent.');

    Fx::actAsStaff(Fx::staffWith(B2BPermissions::staff(), ['sa']));
    app(ApproveCompanyHandler::class)->handle(new ApproveCompany($company->id(), 'Welcome aboard.'));

    Fx::actAsCustomer($customerId);
    app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
    app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument(B2BFixtures::documentTypes()[0]->id(), B2BFixtures::pdf(), 'new.pdf'));
    $draft = app(ApplicationRepository::class)->openFor($customerId) ?? throw new LogicException('No draft.');

    return [
        'customerId' => $customerId,
        'company' => $company,
        'sent' => [$first->id(), $second->id()],
        'draftId' => $draft->id(),
        'answerFile' => (string) DB::table('b2b.application_request_answers')->where('application_id', $second->id())->whereNotNull('media_id')->value('media_id'),
        'draftFile' => $draft->documents()[B2BFixtures::documentTypes()[0]->id()]->mediaId,
    ];
}

/**
 * Every file any of these applications holds, papers and answers.
 *
 * @param  list<string>  $applicationIds
 * @return list<string>
 */
function companyAnonymizeFiles(array $applicationIds): array
{
    return array_values(array_unique([
        ...DB::table('b2b.application_documents')->whereIn('application_id', $applicationIds)->pluck('media_id')->map(static fn (mixed $id): string => (string) $id)->all(),
        ...DB::table('b2b.application_request_answers')->whereIn('application_id', $applicationIds)->whereNotNull('media_id')->pluck('media_id')->map(static fn (mixed $id): string => (string) $id)->all(),
    ]));
}

/**
 * Where these files sit on disk, as [disk, key] — read before they are deleted.
 *
 * @param  list<string>  $mediaIds
 * @return list<array{0: string, 1: string}>
 */
function companyAnonymizeObjects(array $mediaIds): array
{
    return array_values(DB::table('platform.media')->whereIn('id', $mediaIds)->get(['disk', 'object_key'])
        ->map(static fn (stdClass $row): array => [(string) $row->disk, (string) $row->object_key])
        ->all());
}

/**
 * B2B's part of anonymizing, as Access's sweep leaves it on the (faked) queue.
 *
 * @return list<CallQueuedListener>
 */
function companyAnonymizeQueued(): array
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new LogicException('The queue is not faked: beforeEach fakes it.');
    }

    return array_values($queue->pushed(CallQueuedListener::class, static fn (CallQueuedListener $job): bool => $job->class === AnonymizeCompany::class)->all());
}

/**
 * Runs a queued job as a worker would, by the system.
 */
function companyAnonymizeWork(CallQueuedListener $job): void
{
    $job->setJob(new FakeJob);
    Fx::asSystem(fn () => $job->handle(app()));
}

function companyAnonymizeRun(string $customerId): void
{
    Fx::asSystem(fn () => app(CompanyAnonymizer::class)->anonymize($customerId));
}

/**
 * @return array<string, mixed> the changes the newest such entry recorded, in a steady key order
 */
function companyAnonymizeChanges(string $action, string $subjectId): array
{
    $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->orderByDesc('id')->value('changes'), true);
    $changes = is_array($changes) ? $changes : [];
    ksort($changes);

    return $changes;
}

describe('a company with a history (scenario 18)', function () {
    it('empties the company and every application it sent, keeping the company, its type, status and decisions', function () {
        $history = companyAnonymizeHistory();
        $company = app(CompanyRepository::class)->forCustomer($history['customerId']) ?? throw new LogicException('No company.');

        companyAnonymizeRun($history['customerId']);

        $after = app(CompanyRepository::class)->find($company->id());
        expect($after?->id())->toBe($company->id())
            ->and([$after?->details()->name->value, $after?->details()->crNumber->value, $after?->details()->taxNumber->value, $after?->details()->address->value])
            ->toBe(['Deleted company', 'Deleted', 'Deleted', 'Deleted'])
            ->and($after?->details()->type->equals($company->details()->type))->toBeTrue()
            ->and($after?->status())->toBe(CompanyStatus::Approved)
            ->and($after?->statusChangedBy())->toBe($company->statusChangedBy());

        [$first, $second] = array_map(static fn (string $id) => app(ApplicationRepository::class)->find($id), $history['sent']);

        foreach ([$first, $second] as $sent) {
            expect([$sent?->name()?->value, $sent?->crNumber()?->value, $sent?->taxNumber()?->value, $sent?->address()?->value])
                ->toBe(['Deleted company', 'Deleted', 'Deleted', 'Deleted'])
                ->and($sent?->note())->toBeNull()
                ->and($sent?->documents())->toBe([])
                ->and($sent?->answers())->toBe([])
                ->and($sent?->type())->not->toBeNull()
                ->and($sent?->decidedBy())->not->toBeNull();
        }

        expect($first?->state())->toBe(ApplicationState::Rejected)
            ->and($first?->decisionReason()?->value)->toBe('The CR number does not match the certificate.')
            // Staff's requests stay: they are what the company was told.
            ->and($first?->requests())->toHaveCount(2)
            ->and($second?->state())->toBe(ApplicationState::Approved)
            ->and($second?->decisionReason()?->value)->toBe('Welcome aboard.');
    });

    it('deletes the unsent draft whole, and every paper and answer file, from the media and the disk too', function () {
        $history = companyAnonymizeHistory();
        $files = companyAnonymizeFiles([...$history['sent'], $history['draftId']]);
        // The two uploaded through the use cases are on the disk; the fixture's papers are rows only.
        $uploaded = companyAnonymizeObjects([$history['answerFile'], $history['draftFile']]);
        $storedBefore = array_filter($uploaded, static fn (array $object): bool => Storage::disk($object[0])->exists($object[1]));

        companyAnonymizeRun($history['customerId']);

        expect($storedBefore)->toHaveCount(2)
            ->and(array_filter($uploaded, static fn (array $object): bool => Storage::disk($object[0])->exists($object[1])))->toBe([])
            ->and($files)->toContain($history['answerFile'], $history['draftFile'])
            ->and(count($files))->toBeGreaterThan(2)
            ->and(app(ApplicationRepository::class)->find($history['draftId']))->toBeNull()
            ->and(app(ApplicationRepository::class)->openFor($history['customerId']))->toBeNull()
            ->and(DB::table('platform.media')->whereIn('id', $files)->count())->toBe(0)
            ->and(DB::table('b2b.application_documents')->whereIn('application_id', $history['sent'])->count())->toBe(0)
            ->and(DB::table('b2b.application_request_answers')->whereIn('application_id', $history['sent'])->count())->toBe(0);
    });

    it('records the company emptied, by the system, and the draft discarded', function () {
        $history = companyAnonymizeHistory();
        $files = companyAnonymizeFiles([...$history['sent'], $history['draftId']]);

        companyAnonymizeRun($history['customerId']);

        $entry = DB::table('platform.audit_entries')->where('action', 'b2b.company.anonymized')->where('subject_id', $history['company']->id())->first();

        expect($entry?->actor_type)->toBe('SYSTEM')
            ->and($entry?->store_id)->toBe($history['company']->homeStoreId())
            ->and(companyAnonymizeChanges('b2b.company.anonymized', $history['company']->id()))->toBe([
                'address' => 'changed',
                'applications_anonymized' => [0, 2],
                'cr_number' => 'changed',
                'files_deleted' => [0, count($files)],
                'name' => 'changed',
                'tax_number' => 'changed',
            ])
            ->and(companyAnonymizeChanges('b2b.application.discarded', $history['draftId']))->toBe(['state' => ['DRAFT', null]]);
    });

    it('changes nothing, and records nothing, a second time', function () {
        $history = companyAnonymizeHistory();
        companyAnonymizeRun($history['customerId']);
        $entries = DB::table('platform.audit_entries')->count();
        $rows = DB::table('b2b.applications')->where('customer_id', $history['customerId'])->orderBy('id')->get()->toArray();

        companyAnonymizeRun($history['customerId']);

        expect(DB::table('platform.audit_entries')->count())->toBe($entries)
            ->and(DB::table('b2b.applications')->where('customer_id', $history['customerId'])->orderBy('id')->get()->toArray())->toEqual($rows);
    });

    it('leaves every other account\'s company, applications and papers alone', function () {
        $otherId = B2BFixtures::verifiedCompanyAccount();
        [$other, $otherSent] = B2BFixtures::sent($otherId);
        $otherFiles = companyAnonymizeFiles([$otherSent->id()]);
        $history = companyAnonymizeHistory();

        companyAnonymizeRun($history['customerId']);

        expect(app(CompanyRepository::class)->forCustomer($otherId)?->details()->name->value)->toBe($other->details()->name->value)
            ->and(app(ApplicationRepository::class)->find($otherSent->id())?->documents())->toHaveCount(count($otherSent->documents()))
            ->and(DB::table('platform.media')->whereIn('id', $otherFiles)->count())->toBe(count($otherFiles));
    });

    it('works inside its own transaction, under the account\'s lock', function () {
        $history = companyAnonymizeHistory();
        $locks = B2BFixtures::accountLocks();
        $audits = B2BFixtures::auditLevels();

        companyAnonymizeRun($history['customerId']);

        expect($locks->getArrayCopy())->toContain(['exclusive', 'b2b:account:'.$history['customerId'], 2])
            ->and(array_values(array_unique(array_column(array_filter($audits->getArrayCopy(), static fn (array $a): bool => str_starts_with($a[0], 'b2b.')), 1))))->toBe([2]);
    });
});

describe('a company whose application is still waiting', function () {
    it('keeps the application: it was sent, so it keeps its record, with placeholders', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [, $sent] = B2BFixtures::sent($customerId);

        companyAnonymizeRun($customerId);

        $after = app(ApplicationRepository::class)->find($sent->id());
        expect($after?->state())->toBe(ApplicationState::Submitted)
            ->and($after?->name()?->value)->toBe('Deleted company')
            ->and($after?->documents())->toBe([])
            ->and(companyAnonymizeChanges('b2b.application.discarded', $sent->id()))->toBe([]);
    });
});

describe('an account with no company', function () {
    it('deletes a first draft whole, with its papers, and records it discarded', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $draft = B2BFixtures::storedDraft($customerId);
        $files = companyAnonymizeFiles([$draft->id()]);

        companyAnonymizeRun($customerId);

        expect($files)->not->toBe([])
            ->and(app(ApplicationRepository::class)->find($draft->id()))->toBeNull()
            ->and(DB::table('platform.media')->whereIn('id', $files)->count())->toBe(0)
            ->and(companyAnonymizeChanges('b2b.application.discarded', $draft->id()))->toBe(['state' => ['DRAFT', null]])
            ->and(DB::table('platform.audit_entries')->where('action', 'b2b.company.anonymized')->count())->toBe(0);
    });

    it('leaves an individual account alone: nothing changes, nothing is recorded', function () {
        $customerId = Fx::customer(strtolower((string) Str::ulid()).'@example.test');
        $entries = DB::table('platform.audit_entries')->where('action', 'like', 'b2b.%')->count();

        companyAnonymizeRun($customerId);

        expect(DB::table('platform.audit_entries')->where('action', 'like', 'b2b.%')->count())->toBe($entries);
    });
});

describe('a company still "Other" (amendment 13(a))', function () {
    it('gives up its own words for its type, on the company and the application that sent them', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $draft = B2BFixtures::storedDraft($customerId);
        $draft->describe(
            CompanyName::of('Al Noor Trading'),
            CompanyTypeChoice::other('Cooperative of one'),
            RegistrationNumber::of('cr_number', '1010123456'),
            RegistrationNumber::of('tax_number', '300123456700003'),
            CompanyAddress::of("King Fahd Road\nRiyadh"),
            null,
        );
        app(ApplicationRepository::class)->update($draft);
        Fx::actAsCustomer($customerId);
        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
        $company = app(CompanyRepository::class)->forCustomer($customerId) ?? throw new LogicException('Not sent.');

        companyAnonymizeRun($customerId);

        $after = app(CompanyRepository::class)->find($company->id());
        $sent = app(ApplicationRepository::class)->find($draft->id());
        expect($company->details()->type->other)->toBe('Cooperative of one')
            ->and($after?->details()->type->isOther())->toBeTrue()
            ->and($after?->details()->type->other)->toBe('Deleted')
            ->and($sent?->type()?->other)->toBe('Deleted')
            ->and(companyAnonymizeChanges('b2b.company.anonymized', $company->id()))->toHaveKey('company_type_other', 'changed');
    });
});

describe('from Access\'s nightly sweep (amendment 13(a))', function () {
    it('only queues B2B\'s part — the sweep counts the account done — and the queued job empties the company', function () {
        $history = companyAnonymizeHistory();
        DB::table('access.customers')->where('id', $history['customerId'])->update(['deletion_scheduled_for' => CarbonImmutable::now()->subMinute()]);

        $done = Fx::asSystem(fn (): int => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));
        $queued = companyAnonymizeQueued();

        expect($done)->toBe(1)
            ->and($queued)->toHaveCount(1)
            ->and($queued[0]->data[0]->customerId ?? null)->toBe($history['customerId'])
            ->and($queued[0]->tries)->toBe(5)
            ->and($queued[0]->backoff)->toBe([10, 60, 300, 1800])
            // Nothing happens here until a worker runs it.
            ->and(app(CompanyRepository::class)->forCustomer($history['customerId'])?->details()->name->value)->toBe('Al Noor Trading');

        companyAnonymizeWork($queued[0]);

        expect(app(CompanyRepository::class)->find($history['company']->id())?->details()->name->value)->toBe('Deleted company')
            ->and(app(ApplicationRepository::class)->find($history['draftId']))->toBeNull()
            ->and(DB::table('platform.media')->whereIn('id', [$history['answerFile'], $history['draftFile']])->count())->toBe(0);
    });

    it('is done once however many times the job runs: the second run changes and records nothing', function () {
        $history = companyAnonymizeHistory();
        DB::table('access.customers')->where('id', $history['customerId'])->update(['deletion_scheduled_for' => CarbonImmutable::now()->subMinute()]);
        Fx::asSystem(fn (): int => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));
        $job = companyAnonymizeQueued()[0] ?? throw new LogicException('Nothing queued.');

        companyAnonymizeWork($job);
        $entries = DB::table('platform.audit_entries')->count();
        $rows = DB::table('b2b.applications')->where('customer_id', $history['customerId'])->orderBy('id')->get()->toArray();
        companyAnonymizeWork($job);

        expect(DB::table('platform.audit_entries')->count())->toBe($entries)
            ->and(DB::table('b2b.applications')->where('customer_id', $history['customerId'])->orderBy('id')->get()->toArray())->toEqual($rows);
    });
});
