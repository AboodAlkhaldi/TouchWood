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
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequest;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequestHandler;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\DownloadCompanyDocument\DownloadCompanyDocument;
use Modules\B2B\Application\Command\DownloadCompanyDocument\DownloadCompanyDocumentHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Application\Query\ListCompanies\CompanyPage;
use Modules\B2B\Application\Query\ListCompanies\CompanySummary;
use Modules\B2B\Application\Query\ListCompanies\ListCompanies;
use Modules\B2B\Application\Query\ListCompanies\ListCompaniesHandler;
use Modules\B2B\Application\Query\ViewCompany\StaffCompanyView;
use Modules\B2B\Application\Query\ViewCompany\ViewCompany;
use Modules\B2B\Application\Query\ViewCompany\ViewCompanyHandler;
use Modules\B2B\Domain\Exception\ApplicationFileNotFound;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 4: what staff read (b2b.md §3.2, amendment 10) — the company list, one company with the
| applications it sent, and its papers, each opening audited.
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
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>|null  $permissions
 * @param  list<string>  $stores
 */
function staffCompanyViewReader(?array $permissions = null, array $stores = ['sa']): string
{
    $staffId = Fx::staffWith($permissions ?? B2BPermissions::staff(), $stores);
    Fx::actAsStaff($staffId);

    return $staffId;
}

function staffCompanyViewList(?string $search = null, ?string $status = null, ?string $storeId = null, int $page = 1, int $perPage = 25): CompanyPage
{
    return app(ListCompaniesHandler::class)->handle(new ListCompanies($search, $status, $storeId, $page, $perPage));
}

function staffCompanyViewOf(string $companyId): StaffCompanyView
{
    return app(ViewCompanyHandler::class)->handle(new ViewCompany($companyId));
}

/**
 * A sent company, renamed and with its own numbers, so the list can tell them apart.
 */
function staffCompanyViewCompany(string $name, string $cr = '1010000001', string $tax = '300000000000001', string $store = 'sa'): string
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::sent($customerId);
    DB::table('b2b.companies')->where('id', $company->id())->update(['name' => $name, 'cr_number' => $cr, 'tax_number' => $tax, 'home_store_id' => Fx::storeId($store)]);

    return $company->id();
}

/**
 * @return list<string>
 */
function staffCompanyViewIds(CompanyPage $page): array
{
    return array_map(static fn (CompanySummary $row): string => $row->id, $page->companies);
}

describe('the company list (§3.2, amendment 10(g), (j))', function () {
    it('shows only the companies of the reader\'s stores, counted the same way; every store for a Super Admin', function () {
        $ours = staffCompanyViewCompany('Al Noor Trading');
        $theirs = staffCompanyViewCompany('Nile Supplies', store: 'eg');
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        $page = staffCompanyViewList();

        expect(staffCompanyViewIds($page))->toBe([$ours])
            ->and($page->total)->toBe(1);

        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(staffCompanyViewIds(staffCompanyViewList()))->toEqualCanonicalizing([$ours, $theirs])
            ->and(staffCompanyViewIds(staffCompanyViewList(storeId: Fx::storeId('eg'))))->toBe([$theirs]);
    });

    it('refuses a store the reader does not cover, a store that is not one, and a status that is not one', function () {
        staffCompanyViewCompany('Al Noor Trading');
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        expect(fn () => staffCompanyViewList(storeId: Fx::storeId('eg')))->toThrow(Unauthorized::class)
            ->and(fn () => staffCompanyViewList(storeId: 'not-a-store'))->toThrow(InvalidCompanyAttribute::class)
            ->and(fn () => staffCompanyViewList(status: 'ARCHIVED'))->toThrow(InvalidCompanyAttribute::class)
            ->and(staffCompanyViewIds(staffCompanyViewList(storeId: Fx::storeId('sa'))))->toHaveCount(1);
    });

    it('refuses someone who may not view companies in any store', function () {
        // Reviewing is another job: it does not let anyone read the list (amendment 10(a)).
        staffCompanyViewReader([B2BPermissions::COMPANY_REVIEW, 'access.customer.view']);

        expect(fn () => staffCompanyViewList())->toThrow(Unauthorized::class);
    });

    it('filters by status, and searches the name, the CR number and the tax number — a % meaning itself', function () {
        $noor = staffCompanyViewCompany('Al Noor Trading', '1010111111', '300111111100003');
        $delta = staffCompanyViewCompany('Delta 100% Foods', '2020222222', '300222222200003');
        [$approved] = B2BFixtures::approved(B2BFixtures::verifiedCompanyAccount());
        DB::table('b2b.companies')->where('id', $approved->id())->update(['name' => 'Gulf Steel', 'cr_number' => '3030333333', 'tax_number' => '300333333300003']);
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        expect(staffCompanyViewIds(staffCompanyViewList(search: 'noor')))->toBe([$noor])
            ->and(staffCompanyViewIds(staffCompanyViewList(search: '2020222')))->toBe([$delta])
            ->and(staffCompanyViewIds(staffCompanyViewList(search: '3001111')))->toBe([$noor])
            ->and(staffCompanyViewIds(staffCompanyViewList(search: '100%')))->toBe([$delta])
            ->and(staffCompanyViewIds(staffCompanyViewList(search: '%')))->toBe([$delta])
            // Typed on an Arabic or Persian keyboard: the same number, saved in Latin (amendment 29).
            ->and(staffCompanyViewIds(staffCompanyViewList(search: '٢٠٢٠٢٢٢')))->toBe([$delta])
            ->and(staffCompanyViewIds(staffCompanyViewList(search: '۳۰۰۱۱۱۱')))->toBe([$noor])
            ->and(staffCompanyViewIds(staffCompanyViewList(status: 'pending')))->toEqualCanonicalizing([$noor, $delta])
            ->and(staffCompanyViewList(status: 'APPROVED')->total)->toBe(1);
    });

    it('puts waiting companies first, the oldest sent first, then the others by their latest status change, and pages them', function () {
        CarbonImmutable::setTestNow('2026-09-20 10:00:00');
        $decidedFirst = B2BFixtures::rejected(B2BFixtures::verifiedCompanyAccount())[0]->id();
        CarbonImmutable::setTestNow('2026-09-21 10:00:00');
        $waitingLonger = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount())[0]->id();
        CarbonImmutable::setTestNow('2026-09-22 10:00:00');
        $decidedLast = B2BFixtures::approved(B2BFixtures::verifiedCompanyAccount())[0]->id();
        CarbonImmutable::setTestNow('2026-09-23 10:00:00');
        $waitingNewer = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount())[0]->id();
        CarbonImmutable::setTestNow();
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        $all = staffCompanyViewList();
        $second = staffCompanyViewList(page: 2, perPage: 3);

        expect(staffCompanyViewIds($all))->toBe([$waitingLonger, $waitingNewer, $decidedLast, $decidedFirst])
            ->and($all->companies[0]->waitingSince)->toStartWith('2026-09-21')
            ->and($all->companies[2]->waitingSince)->toBeNull()
            ->and(staffCompanyViewIds($second))->toBe([$decidedFirst])
            ->and($second->total)->toBe(4);
    });

    it('keeps the page within bounds, as the customer list does (the review of step 4)', function () {
        staffCompanyViewCompany('Al Noor Trading');
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        $huge = staffCompanyViewList(page: PHP_INT_MAX, perPage: 500);
        $none = staffCompanyViewList(page: 0, perPage: 0);

        expect($huge->page)->toBe(100_000)
            ->and($huge->perPage)->toBe(100)
            ->and($huge->companies)->toBe([])
            ->and($huge->total)->toBe(1)
            ->and($none->page)->toBe(1)
            ->and($none->perPage)->toBe(1)
            ->and($none->companies)->toHaveCount(1);
    });

    it('marks a waiting company whose sent type was deactivated since (§1.3)', function () {
        $companyId = staffCompanyViewCompany('Al Noor Trading');
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        expect(staffCompanyViewList()->companies[0]->typeDeactivatedSinceSent)->toBeFalse();

        B2BFixtures::deactivate(B2BFixtures::companyTypes()[1]);

        expect(staffCompanyViewList()->companies[0]->id)->toBe($companyId)
            ->and(staffCompanyViewList()->companies[0]->typeDeactivatedSinceSent)->toBeTrue();
    });
});

describe('one company (§3.2)', function () {
    it('shows the company, its holder, and the applications it sent newest first with who decided each', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company, $first] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        $view = staffCompanyViewOf($company->id());
        $decider = DB::table('access.staff_users')->where('id', $first->decidedBy())->first() ?? throw new LogicException('No decider.');

        expect($view->id)->toBe($company->id())
            ->and($view->status)->toBe('PENDING')
            ->and($view->holder?->email)->toBe((string) DB::table('access.customers')->where('id', $customerId)->value('email'))
            ->and(array_map(static fn ($sent): string => $sent->state, $view->applications))->toBe(['SUBMITTED', 'REJECTED'])
            ->and($view->applications[1]->id)->toBe($first->id())
            ->and($view->applications[1]->decidedBy)->toBe(trim($decider->first_name.' '.$decider->last_name))
            ->and($view->applications[1]->decisionReason)->toBe('The CR number does not match the certificate.')
            ->and($view->applications[0]->decidedBy)->toBeNull()
            ->and($view->applications[0]->typeDeactivatedSinceSent)->toBeFalse();
    });

    it('shows each sent application\'s papers, flags and requests, and the company\'s details (the review of step 4)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $requestId = strtolower((string) Str::ulid());
        $documentType = B2BFixtures::documentTypes()[0]->id();
        [$company, $rejected] = B2BFixtures::rejected(
            $customerId,
            [ApplicationFlag::field(FlaggedField::CrNumber), ApplicationFlag::document($documentType)],
            [ApplicationRequest::add($requestId, RequestKind::Text, 'Who signs for the company?', 0)],
        );
        // Deactivated after the decision: only a waiting application is marked (§1.3).
        B2BFixtures::deactivate(B2BFixtures::companyTypes()[1]);
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        $view = staffCompanyViewOf($company->id());
        $sent = $view->applications[0];
        $flags = array_map(static fn ($flag): array => [$flag->field, $flag->documentTypeId], $sent->flags);
        sort($flags);

        expect($view->details->name)->toBe('Al Noor Trading')
            ->and($view->details->crNumber)->toBe('1010123456')
            ->and($view->status)->toBe('REJECTED')
            ->and($view->statusReason)->toBe('The CR number does not match the certificate.')
            ->and($view->mayOrder)->toBeFalse()
            ->and(array_map(static fn ($file): string => $file->mediaId, $sent->documents))
            ->toEqualCanonicalizing(array_values(array_map(static fn ($document): string => $document->mediaId, $rejected->documents())))
            ->and($sent->documents)->not->toBe([])
            ->and($flags)->toBe([[null, $documentType], ['cr_number', null]])
            ->and(array_map(static fn ($request): array => [$request->id, $request->kind, $request->label], $sent->requests))
            ->toBe([[$requestId, 'TEXT', 'Who signs for the company?']])
            ->and($sent->typeDeactivatedSinceSent)->toBeFalse();
    });

    it('never shows a draft: nothing is reviewed until it is sent (§1.2)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company, $rejected] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        $view = staffCompanyViewOf($company->id());

        expect(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('sa'))?->state()->value)->toBe('DRAFT')
            ->and(array_map(static fn ($sent): string => $sent->id, $view->applications))->toBe([$rejected->id()]);
    });

    it('marks the waiting application whose type was deactivated since it was sent', function () {
        [$company] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
        B2BFixtures::deactivate(B2BFixtures::companyTypes()[1]);
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);

        expect(staffCompanyViewOf($company->id())->applications[0]->typeDeactivatedSinceSent)->toBeTrue();
    });

    it('reads under the account\'s lock, shared, inside its own transaction', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::sent($customerId);
        staffCompanyViewReader([B2BPermissions::COMPANY_VIEW]);
        $locks = B2BFixtures::accountLocks();

        staffCompanyViewOf($company->id());

        expect($locks->getArrayCopy())->toBe([['shared', 'b2b:account:'.$customerId, 2]]);
    });
});

describe('a company\'s papers (§1.4, §3.2, amendment 10(f))', function () {
    it('opens a paper of a sent application, and audits the opening without the file\'s id (scenario 24)', function () {
        [$company, $application] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
        $typeId = (string) array_key_first($application->documents());
        $mediaId = $application->documents()[$typeId]->mediaId;
        $staffId = staffCompanyViewReader([B2BPermissions::COMPANY_DOCUMENT_VIEW]);
        $levels = B2BFixtures::auditLevels();

        // As pasted: in capitals, with a space around it.
        $link = app(DownloadCompanyDocumentHandler::class)->handle(new DownloadCompanyDocument($company->id(), ' '.strtoupper($mediaId).' '));
        $entry = DB::table('platform.audit_entries')->where('action', 'b2b.company.document_opened')->first();
        $changes = json_decode((string) $entry?->changes, true);
        ksort($changes);

        expect($link->url)->not->toBe('')
            ->and($link->expiresAt->getTimestamp() - time())->toBeGreaterThan(29 * 60)->toBeLessThanOrEqual(30 * 60)
            ->and($levels->getArrayCopy())->toBe([['b2b.company.document_opened', 2]])
            ->and($entry?->subject_id)->toBe($company->id())
            // Who opened it, in the company's home store (scenario 24).
            ->and($entry?->actor_type)->toBe('STAFF')
            ->and($entry?->actor_id)->toBe($staffId)
            ->and($entry?->store_id)->toBe(Fx::storeId('sa'))
            ->and($changes)->toBe(['application_id' => [null, $application->id()], 'document_type_id' => [null, $typeId]])
            ->and((string) $entry?->changes)->not->toContain($mediaId);
    });

    it('opens a file a company sent to answer a request, audited under the request', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $requestId = strtolower((string) Str::ulid());
        [$company] = B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($requestId, RequestKind::File, 'A bank letter', 1)]);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($requestId, path: B2BFixtures::pdf(), originalFilename: 'bank.pdf'));
        $mediaId = (string) app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('sa'))?->answers()[$requestId]->mediaId;
        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
        staffCompanyViewReader([B2BPermissions::COMPANY_DOCUMENT_VIEW]);

        app(DownloadCompanyDocumentHandler::class)->handle(new DownloadCompanyDocument($company->id(), $mediaId));
        $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'b2b.company.document_opened')->value('changes'), true);

        expect($changes['request_id'] ?? null)->toBe([null, $requestId]);
    });

    it('refuses a draft\'s file and another company\'s file exactly as one that does not exist, and audits nothing', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $typeId = B2BFixtures::documentTypes()[0]->id();
        app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($typeId, B2BFixtures::pdf(), 'new.pdf'));
        $draftFile = (string) app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('sa'))?->documents()[$typeId]->mediaId;
        expect($draftFile)->not->toBe('');
        [, $other] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
        $othersFile = array_values($other->documents())[0]->mediaId;
        staffCompanyViewReader([B2BPermissions::COMPANY_DOCUMENT_VIEW]);
        $message = static function (string $mediaId) use ($company): ?string {
            try {
                app(DownloadCompanyDocumentHandler::class)->handle(new DownloadCompanyDocument($company->id(), $mediaId));
            } catch (ApplicationFileNotFound $caught) {
                return $caught->getMessage();
            }

            return null;
        };

        $none = $message(strtolower((string) Str::ulid()));

        expect($none)->not->toBeNull()
            ->and($message($draftFile))->toBe($none)
            ->and($message($othersFile))->toBe($none)
            ->and(Fx::audits('b2b.company.document_opened'))->toBe(0);
    });
});

/*
| Super Admins are invisible (access.md amendment 54): a decision a Super Admin took is named, to
| anyone but another Super Admin, "System administrator", with no name.
*/
it('names a Super Admin who decided "System administrator" to staff, and by name to a Super Admin', function () {
    $customerId = B2BFixtures::companyAccount();
    [$company, $application] = B2BFixtures::sent($customerId);
    $superAdmin = Fx::staff(superAdmin: true, firstName: 'Hidden');
    $application->approve($superAdmin, null, CarbonImmutable::now());
    app(ApplicationRepository::class)->update($application);
    $company->approve($superAdmin, CarbonImmutable::now());
    app(CompanyRepository::class)->update($company);
    app()->setLocale('en');

    staffCompanyViewReader();
    $toStaff = staffCompanyViewOf($company->id());

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $toSuperAdmin = staffCompanyViewOf($company->id());

    expect($toStaff->statusChangedBy)->toBe('System administrator')
        ->and($toStaff->applications[0]->decidedBy)->toBe('System administrator')
        ->and($toSuperAdmin->statusChangedBy)->toBe('Hidden Member')
        ->and($toSuperAdmin->applications[0]->decidedBy)->toBe('Hidden Member');
});
