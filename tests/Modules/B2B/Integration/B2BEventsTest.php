<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompany;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompanyHandler;
use Modules\B2B\Application\Command\CorrectCompanyType\CorrectCompanyType;
use Modules\B2B\Application\Command\CorrectCompanyType\CorrectCompanyTypeHandler;
use Modules\B2B\Application\Command\ReinstateCompany\ReinstateCompany;
use Modules\B2B\Application\Command\ReinstateCompany\ReinstateCompanyHandler;
use Modules\B2B\Application\Command\RejectCompany\RejectCompany;
use Modules\B2B\Application\Command\RejectCompany\RejectCompanyHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Application\Command\SuspendCompany\SuspendCompany;
use Modules\B2B\Application\Command\SuspendCompany\SuspendCompanyHandler;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContact;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContactHandler;
use Modules\B2B\Domain\Exception\InvalidCompanyStatus;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\B2B\Public\Events\CompanyApplicationSubmitted;
use Modules\B2B\Public\Events\CompanyStatusChanged;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| What B2B tells other modules (b2b.md §6): every status change, and every application sent. Both wait
| for the use case's commit, so a change rolled back tells nobody, and a refused one tells nobody.
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
 * Only B2B's own two: every other listener — Platform's, Access's — keeps running for real.
 */
function companyEventsFake(): void
{
    Event::fake([CompanyStatusChanged::class, CompanyApplicationSubmitted::class]);
}

/**
 * Every status change told so far, as [customer, company, from, to, reason].
 *
 * @return list<array{0: string, 1: string, 2: ?CompanyStatus, 3: CompanyStatus, 4: ?string}>
 */
function companyEventsStatusChanges(): array
{
    $changes = [];

    foreach (Event::dispatched(CompanyStatusChanged::class) as $call) {
        $event = $call[0] ?? null;

        if (! $event instanceof CompanyStatusChanged) {
            throw new LogicException('The fake recorded something that is not a status change.');
        }

        $changes[] = [$event->customerId, $event->companyId, $event->from, $event->to, $event->reason];
    }

    return $changes;
}

function companyEventsStatus(string $customerId): ?CompanyStatus
{
    return app(CompanyRepository::class)->forCustomer($customerId)?->status();
}

function companyEventsReviewer(): void
{
    Fx::actAsStaff(Fx::staffWith(B2BPermissions::staff(), ['sa']));
}

function companyEventsSendAgain(string $customerId): void
{
    Fx::actAsCustomer($customerId);
    app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
    app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft(['cr_number' => '1010999999']));
    app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
}

describe('an application sent', function () {
    it('tells that the first one was sent, and that the company it created is PENDING, from no status', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $draft = B2BFixtures::storedDraft($customerId);
        Fx::actAsCustomer($customerId);
        companyEventsFake();

        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);

        $companyId = (string) app(ApplicationRepository::class)->find($draft->id())?->companyId();
        $submitted = Event::dispatched(CompanyApplicationSubmitted::class)->first()[0] ?? null;
        $changed = Event::dispatched(CompanyStatusChanged::class)->first()[0] ?? null;

        Event::assertDispatchedTimes(CompanyApplicationSubmitted::class, 1);
        expect($companyId)->not->toBe('')
            ->and($submitted?->companyId)->toBe($companyId)
            ->and($submitted?->applicationId)->toBe($draft->id())
            ->and(Str::isUuid((string) $submitted?->eventId))->toBeTrue()
            ->and(companyEventsStatusChanges())->toBe([[$customerId, $companyId, null, CompanyStatus::Pending, null]])
            ->and($changed?->eventId)->not->toBe($submitted?->eventId)
            ->and($changed?->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('tells that a later one sends the company back to PENDING, from where it was', function (Closure $arrange, CompanyStatus $from) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = $arrange($customerId);
        companyEventsFake();

        companyEventsSendAgain($customerId);

        Event::assertDispatchedTimes(CompanyApplicationSubmitted::class, 1);
        expect(companyEventsStatusChanges())->toBe([[$customerId, $company->id(), $from, CompanyStatus::Pending, null]]);
    })->with([
        'after a rejection' => [fn (string $customerId) => B2BFixtures::rejected($customerId), CompanyStatus::Rejected],
        'with new details, once approved' => [fn (string $customerId) => B2BFixtures::approved($customerId), CompanyStatus::Approved],
    ]);
});

describe('staff deciding', function () {
    it('tells an approval, with no reason', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::sent($customerId);
        companyEventsReviewer();
        companyEventsFake();

        app(ApproveCompanyHandler::class)->handle(new ApproveCompany($company->id(), 'Welcome aboard.'));

        expect(companyEventsStatusChanges())->toBe([[$customerId, $company->id(), CompanyStatus::Pending, CompanyStatus::Approved, null]]);
        Event::assertNotDispatched(CompanyApplicationSubmitted::class);
    });

    it('tells a rejection, with the reason the company is told', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::sent($customerId);
        companyEventsReviewer();
        companyEventsFake();

        app(RejectCompanyHandler::class)->handle(new RejectCompany($company->id(), 'The CR number does not match.'));

        expect(companyEventsStatusChanges())->toBe([[$customerId, $company->id(), CompanyStatus::Pending, CompanyStatus::Rejected, 'The CR number does not match.']]);
    });

    it('tells a suspension and a reinstatement, each with its reason, back to where it was', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::approved($customerId);
        companyEventsReviewer();
        companyEventsFake();

        app(SuspendCompanyHandler::class)->handle(new SuspendCompany($company->id(), 'The tax number is being checked.'));
        app(ReinstateCompanyHandler::class)->handle(new ReinstateCompany($company->id(), 'The tax number checks out.'));

        expect(companyEventsStatusChanges())->toBe([
            [$customerId, $company->id(), CompanyStatus::Approved, CompanyStatus::Suspended, 'The tax number is being checked.'],
            [$customerId, $company->id(), CompanyStatus::Suspended, CompanyStatus::Approved, 'The tax number checks out.'],
        ]);
    });
});

describe('telling nobody', function () {
    it('tells nobody of a change that was rolled back', function (Closure $act) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::sent($customerId);
        companyEventsReviewer();
        companyEventsFake();

        try {
            DB::transaction(function () use ($act, $company): void {
                $act($company->id());

                throw new RuntimeException('Rolled back after the change.');
            });
        } catch (RuntimeException) {
        }

        Event::assertNothingDispatched();
        expect(companyEventsStatus($customerId))->toBe(CompanyStatus::Pending);
    })->with([
        'an approval' => [fn (string $companyId) => app(ApproveCompanyHandler::class)->handle(new ApproveCompany($companyId))],
        'a suspension' => [fn (string $companyId) => app(SuspendCompanyHandler::class)->handle(new SuspendCompany($companyId, 'Checking.'))],
    ]);

    it('tells nobody of a send that was rolled back', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::storedDraft($customerId);
        Fx::actAsCustomer($customerId);
        companyEventsFake();

        try {
            DB::transaction(function (): void {
                app(SubmitApplicationHandler::class)->handle(new SubmitApplication);

                throw new RuntimeException('Rolled back after the send.');
            });
        } catch (RuntimeException) {
        }

        Event::assertNothingDispatched();
        expect(companyEventsStatus($customerId))->toBeNull();
    });

    it('tells nobody of a decision refused', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        companyEventsReviewer();
        companyEventsFake();

        expect(fn () => app(ApproveCompanyHandler::class)->handle(new ApproveCompany($company->id())))->toThrow(InvalidCompanyStatus::class);
        Event::assertNothingDispatched();
    });

    it('tells nobody of a change that is not a status: the type corrected, the address changed', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::approved($customerId);
        companyEventsReviewer();
        companyEventsFake();

        app(CorrectCompanyTypeHandler::class)->handle(new CorrectCompanyType($company->id(), B2BFixtures::companyTypes()[0]->id()));
        Fx::actAsCustomer($customerId);
        app(UpdateCompanyContactHandler::class)->handle(new UpdateCompanyContact('Olaya Street'));

        Event::assertNothingDispatched();
    });
});
