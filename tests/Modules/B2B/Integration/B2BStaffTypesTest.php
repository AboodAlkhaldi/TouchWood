<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\ActivateCompanyType\ActivateCompanyType;
use Modules\B2B\Application\Command\ActivateCompanyType\ActivateCompanyTypeHandler;
use Modules\B2B\Application\Command\ActivateDocumentType\ActivateDocumentType;
use Modules\B2B\Application\Command\ActivateDocumentType\ActivateDocumentTypeHandler;
use Modules\B2B\Application\Command\AddCompanyType\AddCompanyType;
use Modules\B2B\Application\Command\AddCompanyType\AddCompanyTypeHandler;
use Modules\B2B\Application\Command\AddDocumentType\AddDocumentType;
use Modules\B2B\Application\Command\AddDocumentType\AddDocumentTypeHandler;
use Modules\B2B\Application\Command\DeactivateCompanyType\DeactivateCompanyType;
use Modules\B2B\Application\Command\DeactivateCompanyType\DeactivateCompanyTypeHandler;
use Modules\B2B\Application\Command\DeactivateDocumentType\DeactivateDocumentType;
use Modules\B2B\Application\Command\DeactivateDocumentType\DeactivateDocumentTypeHandler;
use Modules\B2B\Application\Command\MarkTypeListsReviewed\MarkTypeListsReviewed;
use Modules\B2B\Application\Command\MarkTypeListsReviewed\MarkTypeListsReviewedHandler;
use Modules\B2B\Application\Command\MoveCompanyType\MoveCompanyType;
use Modules\B2B\Application\Command\MoveCompanyType\MoveCompanyTypeHandler;
use Modules\B2B\Application\Command\MoveDocumentType\MoveDocumentType;
use Modules\B2B\Application\Command\MoveDocumentType\MoveDocumentTypeHandler;
use Modules\B2B\Application\Command\RenameCompanyType\RenameCompanyType;
use Modules\B2B\Application\Command\RenameCompanyType\RenameCompanyTypeHandler;
use Modules\B2B\Application\Command\RenameDocumentType\RenameDocumentType;
use Modules\B2B\Application\Command\RenameDocumentType\RenameDocumentTypeHandler;
use Modules\B2B\Application\Command\RequireDocumentType\RequireDocumentType;
use Modules\B2B\Application\Command\RequireDocumentType\RequireDocumentTypeHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\TransferCompanyType\TransferCompanyType;
use Modules\B2B\Application\Command\TransferCompanyType\TransferCompanyTypeHandler;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\TypeNameTaken;
use Modules\B2B\Domain\Exception\TypeNotFound;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Public\Enums\CompanyStatus;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 4: staff changing a store's two lists (b2b.md §1.3, §3.2; amendments 5 and 10) — who may,
| names unique per store, never deleted but activated again, deactivating a company type with its
| companies left or replaced, the "copied" notice, the audit log and the lock order.
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
 * @param  list<string>|null  $permissions
 * @param  list<string>  $stores
 */
function staffTypesAdmin(?array $permissions = null, array $stores = ['sa']): string
{
    $staffId = Fx::staffWith($permissions ?? B2BPermissions::staff(), $stores);
    Fx::actAsStaff($staffId);

    return $staffId;
}

function staffTypesNotice(string $storeCode = 'sa'): ?bool
{
    $value = DB::table('b2b.store_type_lists')->where('store_id', Fx::storeId($storeCode))->value('copied_not_reviewed');

    return $value === null ? null : (bool) $value;
}

/**
 * @return array<string, mixed>
 */
function staffTypesChanges(string $action, string $subjectId): array
{
    $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->orderByDesc('id')->value('changes'), true);
    $changes = is_array($changes) ? $changes : [];
    ksort($changes);

    return $changes;
}

function staffTypesDeactivate(string $typeId, ?string $replacement = null, InactiveTypeDisplay $shown = InactiveTypeDisplay::Hidden): void
{
    app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($typeId, $shown, $replacement));
}

/**
 * Every change to a list that names a type, with the job it needs.
 *
 * @return array<string, array{0: Closure(): string, 1: Closure(string): mixed}>
 */
function staffTypesOnAType(): array
{
    $company = fn (): string => B2BFixtures::companyTypes()[0]->id();
    $document = fn (): string => B2BFixtures::documentTypes()[0]->id();

    return [
        'renaming a company type' => [$company, fn (string $id) => app(RenameCompanyTypeHandler::class)->handle(new RenameCompanyType($id, 'نوع جديد', 'A new type'))],
        'moving a company type' => [$company, fn (string $id) => app(MoveCompanyTypeHandler::class)->handle(new MoveCompanyType($id, 900))],
        'deactivating a company type' => [$company, fn (string $id) => staffTypesDeactivate($id)],
        'activating a company type' => [$company, fn (string $id) => app(ActivateCompanyTypeHandler::class)->handle(new ActivateCompanyType($id))],
        'renaming a document type' => [$document, fn (string $id) => app(RenameDocumentTypeHandler::class)->handle(new RenameDocumentType($id, 'ورقة جديدة', 'A new paper'))],
        'moving a document type' => [$document, fn (string $id) => app(MoveDocumentTypeHandler::class)->handle(new MoveDocumentType($id, 900))],
        'making a document type optional' => [$document, fn (string $id) => app(RequireDocumentTypeHandler::class)->handle(new RequireDocumentType($id, false))],
        'deactivating a document type' => [$document, fn (string $id) => app(DeactivateDocumentTypeHandler::class)->handle(new DeactivateDocumentType($id, InactiveTypeDisplay::Greyed))],
        'activating a document type' => [$document, fn (string $id) => app(ActivateDocumentTypeHandler::class)->handle(new ActivateDocumentType($id))],
    ];
}

/**
 * Every change to a list that names a store.
 *
 * @return array<string, array{0: Closure(string): mixed}>
 */
function staffTypesOnAStore(): array
{
    return [
        'adding a company type' => [fn (string $storeId) => app(AddCompanyTypeHandler::class)->handle(new AddCompanyType($storeId, 'جمعية تعاونية', 'Cooperative Society', 700))],
        'adding a document type' => [fn (string $storeId) => app(AddDocumentTypeHandler::class)->handle(new AddDocumentType($storeId, 'خطاب بنكي', 'Bank letter', 700, false))],
        'marking the lists reviewed' => [fn (string $storeId) => app(MarkTypeListsReviewedHandler::class)->handle(new MarkTypeListsReviewed($storeId))],
    ];
}

describe('who may change a store\'s lists (§3.2, amendment 10)', function () {
    it('refuses someone who holds the job in no store, before reading anything', function (Closure $type, Closure $change) {
        staffTypesAdmin(['b2b.company.view']);

        expect(fn () => $change($type()))->toThrow(Unauthorized::class);
    })->with(staffTypesOnAType());

    it('answers a type of another store exactly as one that does not exist (10(k))', function (Closure $type, Closure $change) {
        staffTypesAdmin(stores: ['eg']);
        $theirs = $type();

        // The answer names only the id the caller sent, whichever case it is.
        expect(fn () => $change($theirs))->toThrow(TypeNotFound::class, "No type \"{$theirs}\".")
            ->and(fn () => $change('01j8z3k4m5n6p7q8r9s0t1v2x9'))->toThrow(TypeNotFound::class, 'No type "01j8z3k4m5n6p7q8r9s0t1v2x9".')
            ->and(staffTypesNotice())->toBeTrue();
    })->with(staffTypesOnAType());

    it('refuses a store the staff member does not cover, and anything that is not a store', function (Closure $change) {
        staffTypesAdmin(stores: ['sa']);

        expect(fn () => $change(Fx::storeId('eg')))->toThrow(Unauthorized::class)
            ->and(fn () => $change('not-a-store'))->toThrow(InvalidCompanyAttribute::class)
            ->and(staffTypesNotice('eg'))->toBeTrue();

        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => $change(strtolower((string) Str::ulid())))->toThrow(InvalidCompanyAttribute::class);

        // Someone with no such job anywhere is refused by its name, before the store is even read.
        staffTypesAdmin(['b2b.company.view']);

        expect(fn () => $change('not-a-store'))->toThrow(Unauthorized::class);
    })->with(staffTypesOnAStore());

    it('grants one job, never another (scenario 20)', function () {
        staffTypesAdmin([B2BPermissions::COMPANY_TYPE_UPDATE]);
        $type = B2BFixtures::companyTypes()[0]->id();

        expect(fn () => staffTypesDeactivate($type))->toThrow(Unauthorized::class)
            ->and(fn () => app(AddCompanyTypeHandler::class)->handle(new AddCompanyType(Fx::storeId('sa'), 'نوع', 'Kind', 1)))->toThrow(Unauthorized::class)
            ->and(fn () => app(MoveDocumentTypeHandler::class)->handle(new MoveDocumentType(B2BFixtures::documentTypes()[0]->id(), 5)))->toThrow(Unauthorized::class)
            ->and(app(CompanyTypeRepository::class)->find($type)?->isActive())->toBeTrue();
    });
});

describe('adding, renaming and moving (§1.3, amendment 2)', function () {
    it('adds a type to the store\'s list, active, audited by value, and clears the notice', function () {
        staffTypesAdmin();
        expect(staffTypesNotice())->toBeTrue();

        $id = app(AddCompanyTypeHandler::class)->handle(new AddCompanyType(Fx::storeId('sa'), 'جمعية تعاونية', 'Cooperative Society', 700));
        $type = app(CompanyTypeRepository::class)->find($id);

        expect($type?->storeId())->toBe(Fx::storeId('sa'))
            ->and($type?->isActive())->toBeTrue()
            ->and($type?->name()->en)->toBe('Cooperative Society')
            ->and(staffTypesChanges('b2b.company_type.added', $id))->toBe([
                'name_ar' => [null, 'جمعية تعاونية'],
                'name_en' => [null, 'Cooperative Society'],
                'position' => [null, 700],
            ])
            ->and(staffTypesNotice())->toBeFalse()
            ->and(staffTypesNotice('eg'))->toBeTrue();
    });

    it('refuses a name another type of the store has, in either language, ignoring case — and takes it in another store', function () {
        staffTypesAdmin(stores: ['sa', 'eg']);
        $taken = B2BFixtures::companyTypes()[0]->name();
        $add = fn (string $store, string $ar, string $en) => app(AddCompanyTypeHandler::class)->handle(new AddCompanyType(Fx::storeId($store), $ar, $en, 1));
        $count = count(B2BFixtures::companyTypes());

        expect(fn () => $add('sa', 'اسم جديد', strtoupper($taken->en)))->toThrow(TypeNameTaken::class)
            ->and(fn () => $add('sa', $taken->ar, 'A brand new name'))->toThrow(TypeNameTaken::class)
            ->and(fn () => app(RenameCompanyTypeHandler::class)->handle(new RenameCompanyType(B2BFixtures::companyTypes()[1]->id(), $taken->ar, 'Other words')))->toThrow(TypeNameTaken::class)
            ->and(B2BFixtures::companyTypes())->toHaveCount($count)
            ->and(staffTypesNotice())->toBeTrue();

        B2BFixtures::deactivate(B2BFixtures::companyTypes('eg')[0]);
        app(RenameCompanyTypeHandler::class)->handle(new RenameCompanyType(B2BFixtures::companyTypes('eg')[1]->id(), 'اسم مصري', 'An Egyptian name'));
        $inEgypt = $add('eg', 'اسم سعودي', 'A Saudi Only Name');
        $add('sa', 'اسم سعودي', 'A Saudi Only Name');
        $entry = DB::table('platform.audit_entries')->where('action', 'b2b.company_type.added')->where('subject_id', $inEgypt)->first();

        expect(count(B2BFixtures::companyTypes()))->toBe($count + 1)
            // Logged in the list's own store, so that store's auditors read it (the review of step 4).
            ->and($entry?->store_id)->toBe(Fx::storeId('eg'))
            ->and($entry?->actor_type)->toBe('STAFF');
    });

    it('renames and moves a type, audited from and to; a change to nothing writes nothing and keeps the notice', function () {
        staffTypesAdmin();
        $type = B2BFixtures::documentTypes()[0];

        app(RenameDocumentTypeHandler::class)->handle(new RenameDocumentType($type->id(), $type->name()->ar, $type->name()->en));
        app(MoveDocumentTypeHandler::class)->handle(new MoveDocumentType($type->id(), $type->position()));

        expect(staffTypesNotice())->toBeTrue()
            ->and(Fx::audits('b2b.document_type.renamed'))->toBe(0)
            ->and(Fx::audits('b2b.document_type.moved'))->toBe(0);

        app(RenameDocumentTypeHandler::class)->handle(new RenameDocumentType($type->id(), $type->name()->ar, 'VAT registration certificate'));
        app(MoveDocumentTypeHandler::class)->handle(new MoveDocumentType($type->id(), 42));

        expect(staffTypesChanges('b2b.document_type.renamed', $type->id()))->toBe(['name_en' => [$type->name()->en, 'VAT registration certificate']])
            ->and(staffTypesChanges('b2b.document_type.moved', $type->id()))->toBe(['position' => [$type->position(), 42]])
            ->and(app(DocumentTypeRepository::class)->find($type->id())?->position())->toBe(42)
            ->and(staffTypesNotice())->toBeFalse();
    });

    it('makes a paper required or optional again, never retroactively', function () {
        [$company] = B2BFixtures::approved(B2BFixtures::verifiedCompanyAccount());
        staffTypesAdmin();
        $id = app(AddDocumentTypeHandler::class)->handle(new AddDocumentType(Fx::storeId('sa'), 'خطاب بنكي', 'Bank letter', 700, false));

        app(RequireDocumentTypeHandler::class)->handle(new RequireDocumentType($id, true));

        expect(app(DocumentTypeRepository::class)->find($id)?->isRequired())->toBeTrue()
            ->and(staffTypesChanges('b2b.document_type.requirement_changed', $id))->toBe(['is_required' => [false, true]])
            // An approved company is never asked for it (§1.3).
            ->and(app(CompanyRepository::class)->find($company->id())?->status())->toBe(CompanyStatus::Approved);
    });
});

describe('the "copied from the Saudi store" notice (§1.3, amendment 10(d))', function () {
    it('goes with any change to either list of the store', function (Closure $type, Closure $change) {
        staffTypesAdmin();
        $id = $type();

        $change($id);

        expect(staffTypesNotice())->toBeFalse()
            ->and(staffTypesNotice('eg'))->toBeTrue();
    })->with(array_diff_key(staffTypesOnAType(), ['activating a company type' => true, 'activating a document type' => true]));

    it('goes with activating a type again', function () {
        $type = B2BFixtures::companyTypes()[0];
        B2BFixtures::deactivate($type);
        staffTypesAdmin();

        app(ActivateCompanyTypeHandler::class)->handle(new ActivateCompanyType($type->id()));

        expect(staffTypesNotice())->toBeFalse();
    });

    it('goes with "Reviewed", pressed by either list\'s update job, audited only while it was showing', function (string $job) {
        staffTypesAdmin([$job]);

        app(MarkTypeListsReviewedHandler::class)->handle(new MarkTypeListsReviewed(Fx::storeId('sa')));
        app(MarkTypeListsReviewedHandler::class)->handle(new MarkTypeListsReviewed(Fx::storeId('sa')));

        expect(staffTypesNotice())->toBeFalse()
            ->and(Fx::audits('b2b.type_lists.reviewed', Fx::storeId('sa')))->toBe(1);
    })->with([B2BPermissions::COMPANY_TYPE_UPDATE, B2BPermissions::DOCUMENT_TYPE_UPDATE]);

    it('refuses "Reviewed" to someone with neither list\'s update job', function () {
        staffTypesAdmin([B2BPermissions::COMPANY_TYPE_CREATE, B2BPermissions::COMPANY_TYPE_DEACTIVATE]);

        expect(fn () => app(MarkTypeListsReviewedHandler::class)->handle(new MarkTypeListsReviewed(Fx::storeId('sa'))))->toThrow(Unauthorized::class)
            ->and(staffTypesNotice())->toBeTrue();
    });
});

describe('deactivating a company type (§1.3, amendments 5 and 10)', function () {
    it('leaves the companies holding it with it, shown as staff choose, audited', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        $held = B2BFixtures::companyTypes()[1];
        staffTypesAdmin();

        staffTypesDeactivate($held->id(), shown: InactiveTypeDisplay::Greyed);

        expect(app(CompanyTypeRepository::class)->find($held->id())?->inactiveDisplay())->toBe(InactiveTypeDisplay::Greyed)
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($held->id())
            ->and(staffTypesChanges('b2b.company_type.deactivated', $held->id()))->toBe([
                'inactive_display' => [null, 'GREYED'],
                'is_active' => [true, false],
            ]);

        // Already inactive: only how it shows changes.
        staffTypesDeactivate($held->id(), shown: InactiveTypeDisplay::Hidden);

        expect(staffTypesChanges('b2b.company_type.deactivated', $held->id()))->toBe(['inactive_display' => ['GREYED', 'HIDDEN']]);
    });

    it('replaces it on every company holding it — approved included — but a suspended one, and leaves every application as it was sent (scenario 25)', function () {
        $held = B2BFixtures::companyTypes()[1];
        $replacement = B2BFixtures::companyTypes()[0];
        $companies = [];

        foreach (['pending' => 'sent', 'approved' => 'approved', 'rejected' => 'rejected'] as $state => $fixture) {
            $customerId = B2BFixtures::verifiedCompanyAccount();
            [$company, $application] = B2BFixtures::{$fixture}($customerId);
            $companies[$state] = [$customerId, $company, $application];
        }

        $suspendedId = B2BFixtures::verifiedCompanyAccount();
        [$suspended] = B2BFixtures::approved($suspendedId);
        B2BFixtures::suspend($suspended);
        staffTypesAdmin();

        staffTypesDeactivate($held->id(), $replacement->id());

        foreach ($companies as $state => [$customerId, $company, $application]) {
            $now = app(CompanyRepository::class)->forCustomer($customerId);

            expect($now?->details()->type->typeId)->toBe($replacement->id(), $state)
                ->and($now?->status())->toBe($company->status(), $state)
                ->and(app(ApplicationRepository::class)->find($application->id())?->type()?->typeId)->toBe($held->id(), $state)
                ->and(staffTypesChanges('b2b.company.type_replaced', $company->id()))->toBe(['company_type_id' => [$held->id(), $replacement->id()]], $state);
        }

        expect(app(CompanyRepository::class)->forCustomer($suspendedId)?->details()->type->typeId)->toBe($held->id())
            ->and(Fx::audits('b2b.company.type_replaced'))->toBe(3)
            ->and(staffTypesChanges('b2b.company_type.deactivated', $held->id()))->toBe([
                'inactive_display' => [null, 'HIDDEN'],
                'is_active' => [true, false],
                'replaced_by' => [null, $replacement->id()],
            ]);
    });

    it('carries the replacement into an open draft that still held the company\'s type, never into a first draft', function () {
        $held = B2BFixtures::companyTypes()[1];
        $replacement = B2BFixtures::companyTypes()[0];
        $reapplying = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::rejected($reapplying);
        Fx::actAsCustomer($reapplying);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        // A first draft, with no company behind it, holding the same type.
        $first = B2BFixtures::companyAccount();
        $draft = Application::draft(app(ApplicationRepository::class)->nextId(), $first, null);
        $draft->describe(CompanyName::of('Nile Supplies'), CompanyTypeChoice::listed($held->id()), null, null, CompanyAddress::of('Riyadh'), null);
        app(ApplicationRepository::class)->add($draft);
        staffTypesAdmin();

        staffTypesDeactivate($held->id(), $replacement->id());

        expect(app(ApplicationRepository::class)->openFor($reapplying)?->type()?->typeId)->toBe($replacement->id())
            ->and(app(ApplicationRepository::class)->openFor($first)?->type()?->typeId)->toBe($held->id());
    });

    it('refuses a replacement that is inactive, unknown, another store\'s or the type itself, and writes nothing', function (Closure $replacement, string $error) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        $held = B2BFixtures::companyTypes()[1];
        staffTypesAdmin();

        expect(fn () => staffTypesDeactivate($held->id(), $replacement()))->toThrow($error)
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue()
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($held->id())
            ->and(staffTypesNotice())->toBeTrue();
    })->with([
        'inactive' => [function () {
            $type = B2BFixtures::companyTypes()[2];
            B2BFixtures::deactivate($type);

            return $type->id();
        }, CompanyTypeInactive::class],
        'unknown' => [fn () => strtolower((string) Str::ulid()), InvalidCompanyAttribute::class],
        'another store\'s' => [fn () => B2BFixtures::companyTypes('eg')[0]->id(), InvalidCompanyAttribute::class],
        'itself' => [fn () => B2BFixtures::companyTypes()[1]->id(), InvalidCompanyAttribute::class],
    ]);

    it('refuses an inactive replacement even when no company holds the type — the refusal is the use case\'s own, not only a company\'s correction (lesson 70)', function () {
        $unheld = B2BFixtures::companyTypes()[3];
        $inactive = B2BFixtures::companyTypes()[2];
        B2BFixtures::deactivate($inactive);
        staffTypesAdmin();

        expect(app(CompanyRepository::class)->holdersOf($unheld->id()))->toBe([])
            ->and(fn () => staffTypesDeactivate($unheld->id(), $inactive->id()))->toThrow(CompanyTypeInactive::class)
            ->and(app(CompanyTypeRepository::class)->find($unheld->id())?->isActive())->toBeTrue();
    });

    it('refuses another store\'s replacement even when no company holds the type (the review of step 4)', function () {
        $unheld = B2BFixtures::companyTypes()[3];
        staffTypesAdmin();

        expect(fn () => staffTypesDeactivate($unheld->id(), B2BFixtures::companyTypes('eg')[0]->id()))->toThrow(InvalidCompanyAttribute::class)
            ->and(app(CompanyTypeRepository::class)->find($unheld->id())?->isActive())->toBeTrue();
    });

    it('writes nothing and keeps the notice when a deactivation changes nothing', function () {
        $type = B2BFixtures::companyTypes()[3];
        B2BFixtures::deactivate($type, InactiveTypeDisplay::Greyed);
        staffTypesAdmin();

        staffTypesDeactivate($type->id(), shown: InactiveTypeDisplay::Greyed);

        expect(Fx::audits('b2b.company_type.deactivated', $type->id()))->toBe(0)
            ->and(staffTypesNotice())->toBeTrue();
    });

    it('activates it again; the companies moved off it stay where they are', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        [$replacement, $held] = B2BFixtures::companyTypes();
        staffTypesAdmin();
        staffTypesDeactivate($held->id(), $replacement->id(), InactiveTypeDisplay::Greyed);

        app(ActivateCompanyTypeHandler::class)->handle(new ActivateCompanyType($held->id()));

        expect(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue()
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->inactiveDisplay())->toBeNull()
            ->and(staffTypesChanges('b2b.company_type.activated', $held->id()))->toBe([
                'inactive_display' => ['GREYED', null],
                'is_active' => [false, true],
            ])
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($replacement->id());
    });
});

describe('replacing a company type with a new one, in one step (§1.3, amendment 11(b))', function () {
    it('adds the new type, deactivates the old one and moves every holder but a suspended one — audited', function () {
        $held = B2BFixtures::companyTypes()[1];
        $movers = [];

        foreach (['sent', 'approved', 'rejected'] as $fixture) {
            $customerId = B2BFixtures::verifiedCompanyAccount();
            B2BFixtures::{$fixture}($customerId);
            $movers[] = $customerId;
        }

        $suspendedId = B2BFixtures::verifiedCompanyAccount();
        [$suspended] = B2BFixtures::approved($suspendedId);
        B2BFixtures::suspend($suspended);
        staffTypesAdmin();

        $newId = app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($held->id(), InactiveTypeDisplay::Greyed, newTypeNameAr: 'شركة شخص واحد', newTypeNameEn: 'One Person Company'));
        $new = app(CompanyTypeRepository::class)->find((string) $newId);

        expect($new?->isActive())->toBeTrue()
            ->and($new?->storeId())->toBe(Fx::storeId('sa'))
            ->and($new?->name()->en)->toBe('One Person Company')
            ->and($new?->position())->toBe($held->position())
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->inactiveDisplay())->toBe(InactiveTypeDisplay::Greyed)
            ->and(array_map(static fn (string $customerId): ?string => app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId, $movers))
            ->toBe([$newId, $newId, $newId])
            ->and(app(CompanyRepository::class)->forCustomer($suspendedId)?->details()->type->typeId)->toBe($held->id())
            ->and(Fx::audits('b2b.company_type.added', (string) $newId))->toBe(1)
            ->and(Fx::audits('b2b.company.type_replaced'))->toBe(3)
            ->and(staffTypesChanges('b2b.company_type.deactivated', $held->id())['replaced_by'] ?? null)->toBe([null, $newId]);
    });

    it('needs the job of adding types as well, and writes nothing without it', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        $held = B2BFixtures::companyTypes()[1];
        $count = count(B2BFixtures::companyTypes());
        staffTypesAdmin([B2BPermissions::COMPANY_TYPE_DEACTIVATE]);

        expect(fn () => app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($held->id(), InactiveTypeDisplay::Hidden, newTypeNameAr: 'نوع جديد', newTypeNameEn: 'A new type')))
            ->toThrow(Unauthorized::class)
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue()
            ->and(B2BFixtures::companyTypes())->toHaveCount($count)
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($held->id());
    });

    it('is all or nothing: a new name another type has leaves the old type active and its holders where they were', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        [$other, $held] = B2BFixtures::companyTypes();
        $count = count(B2BFixtures::companyTypes());
        staffTypesAdmin();

        expect(fn () => app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($held->id(), InactiveTypeDisplay::Hidden, newTypeNameAr: 'اسم جديد', newTypeNameEn: $other->name()->en)))
            ->toThrow(TypeNameTaken::class)
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue()
            ->and(B2BFixtures::companyTypes())->toHaveCount($count)
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($held->id())
            ->and(staffTypesNotice())->toBeTrue();
    });

    it('is all or nothing even when it fails after the new type is written (the review of amendment 11)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        $held = B2BFixtures::companyTypes()[1];
        $count = count(B2BFixtures::companyTypes());
        // The deactivation itself is refused by the database, after the new type was added: only the
        // one transaction around both can take the new type away again. Inside the test's own
        // transaction, so it goes with the test (lesson 36).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION b2b.test_refuse_deactivation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.is_active AND NOT NEW.is_active THEN
                    RAISE EXCEPTION 'refused by the test';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER test_refuse_deactivation BEFORE UPDATE ON b2b.company_types
                FOR EACH ROW EXECUTE FUNCTION b2b.test_refuse_deactivation();
            SQL);
        staffTypesAdmin();

        expect(fn () => app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($held->id(), InactiveTypeDisplay::Hidden, newTypeNameAr: 'نوع جديد', newTypeNameEn: 'A new type')))
            ->toThrow(QueryException::class, 'refused by the test')
            ->and(B2BFixtures::companyTypes())->toHaveCount($count)
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue()
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($held->id())
            ->and(Fx::audits('b2b.company_type.added'))->toBe(0);
    });

    it('refuses a position sent without a new type, rather than ignoring it', function () {
        $held = B2BFixtures::companyTypes()[1];
        staffTypesAdmin();

        expect(fn () => app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($held->id(), InactiveTypeDisplay::Hidden, newTypePosition: 3)))
            ->toThrow(InvalidCompanyAttribute::class)
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue();
    });

    it('takes an existing type or a new one, never both', function () {
        $held = B2BFixtures::companyTypes()[1];
        staffTypesAdmin();

        expect(fn () => app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($held->id(), InactiveTypeDisplay::Hidden, B2BFixtures::companyTypes()[0]->id(), 'نوع جديد', 'A new type')))
            ->toThrow(InvalidCompanyAttribute::class)
            ->and(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue();
    });

    it('lets the old type be activated again later; its former holders stay on the new one', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        $held = B2BFixtures::companyTypes()[1];
        staffTypesAdmin();
        $newId = app(DeactivateCompanyTypeHandler::class)->handle(new DeactivateCompanyType($held->id(), InactiveTypeDisplay::Hidden, newTypeNameAr: 'نوع جديد', newTypeNameEn: 'A new type', newTypePosition: 7));

        app(ActivateCompanyTypeHandler::class)->handle(new ActivateCompanyType($held->id()));

        expect(app(CompanyTypeRepository::class)->find($held->id())?->isActive())->toBeTrue()
            ->and(app(CompanyTypeRepository::class)->find((string) $newId)?->position())->toBe(7)
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($newId);
    });
});

describe('moving every company of one active type to another (§1.3, amendment 11(c))', function () {
    it('moves every holder but a suspended one, keeps both types active, audits each company and the move, and leaves the notice', function () {
        [$to, $from] = B2BFixtures::companyTypes();
        $movers = [];

        foreach (['sent', 'approved', 'rejected'] as $fixture) {
            $customerId = B2BFixtures::verifiedCompanyAccount();
            B2BFixtures::{$fixture}($customerId);
            $movers[] = $customerId;
        }

        // A company reapplying, with its draft still holding the company's type: it follows.
        Fx::actAsCustomer($movers[2]);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $suspendedId = B2BFixtures::verifiedCompanyAccount();
        [$suspended] = B2BFixtures::approved($suspendedId);
        B2BFixtures::suspend($suspended);
        staffTypesAdmin([B2BPermissions::COMPANY_TRANSFER_TYPE]);

        $moved = app(TransferCompanyTypeHandler::class)->handle(new TransferCompanyType($from->id(), $to->id()));
        $first = app(CompanyRepository::class)->forCustomer($movers[0]);

        expect($moved)->toBe(3)
            ->and(array_map(static fn (string $customerId): ?string => app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId, $movers))
            ->toBe([$to->id(), $to->id(), $to->id()])
            ->and(app(ApplicationRepository::class)->openFor($movers[2])?->type()?->typeId)->toBe($to->id())
            ->and(app(CompanyRepository::class)->forCustomer($suspendedId)?->details()->type->typeId)->toBe($from->id())
            ->and(app(CompanyTypeRepository::class)->find($from->id())?->isActive())->toBeTrue()
            ->and(app(CompanyTypeRepository::class)->find($to->id())?->isActive())->toBeTrue()
            ->and(staffTypesChanges('b2b.company.type_transferred', (string) $first?->id()))->toBe(['company_type_id' => [$from->id(), $to->id()]])
            ->and(staffTypesChanges('b2b.company_type.transferred', $from->id()))->toBe(['companies_moved' => [null, 3], 'to_type_id' => [null, $to->id()]])
            ->and(staffTypesNotice())->toBeTrue();
    });

    it('is its own job: neither correcting types nor deactivating them grants it', function () {
        [$to, $from] = B2BFixtures::companyTypes();
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        staffTypesAdmin([B2BPermissions::COMPANY_CORRECT_TYPE, B2BPermissions::COMPANY_TYPE_DEACTIVATE, B2BPermissions::COMPANY_TYPE_UPDATE]);

        expect(fn () => app(TransferCompanyTypeHandler::class)->handle(new TransferCompanyType($from->id(), $to->id())))->toThrow(Unauthorized::class)
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe($from->id());
    });

    it('refuses another store\'s type as one that does not exist, a target that is not another type of the store, and an inactive type — moving nobody', function (Closure $from, Closure $to, string $error) {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId);
        staffTypesAdmin([B2BPermissions::COMPANY_TRANSFER_TYPE]);

        expect(fn () => app(TransferCompanyTypeHandler::class)->handle(new TransferCompanyType($from(), $to())))->toThrow($error)
            ->and(app(CompanyRepository::class)->forCustomer($customerId)?->details()->type->typeId)->toBe(B2BFixtures::companyTypes()[1]->id());
    })->with([
        'from another store' => [fn () => B2BFixtures::companyTypes('eg')[1]->id(), fn () => B2BFixtures::companyTypes('eg')[0]->id(), TypeNotFound::class],
        'to another store' => [fn () => B2BFixtures::companyTypes()[1]->id(), fn () => B2BFixtures::companyTypes('eg')[0]->id(), InvalidCompanyAttribute::class],
        'to an unknown type' => [fn () => B2BFixtures::companyTypes()[1]->id(), fn () => strtolower((string) Str::ulid()), InvalidCompanyAttribute::class],
        'to itself' => [fn () => B2BFixtures::companyTypes()[1]->id(), fn () => B2BFixtures::companyTypes()[1]->id(), InvalidCompanyAttribute::class],
        'to an inactive type' => [fn () => B2BFixtures::companyTypes()[1]->id(), function () {
            $type = B2BFixtures::companyTypes()[0];
            B2BFixtures::deactivate($type);

            return $type->id();
        }, CompanyTypeInactive::class],
        'from an inactive type' => [function () {
            $type = B2BFixtures::companyTypes()[1];
            B2BFixtures::deactivate($type);

            return $type->id();
        }, fn () => B2BFixtures::companyTypes()[0]->id(), CompanyTypeInactive::class],
    ]);

    it('refuses a target of another store, or an inactive one, even when no company holds the type — its own check, not a company\'s (the review of amendment 11)', function (Closure $to, string $error) {
        $from = B2BFixtures::companyTypes()[3];
        staffTypesAdmin([B2BPermissions::COMPANY_TRANSFER_TYPE]);
        $refused = null;

        try {
            app(TransferCompanyTypeHandler::class)->handle(new TransferCompanyType($from->id(), $to()));
        } catch (Throwable $caught) {
            $refused = $caught;
        }

        expect(app(CompanyRepository::class)->holdersOf($from->id()))->toBe([])
            ->and($refused === null ? null : $refused::class)->toBe($error)
            ->and(Fx::audits('b2b.company_type.transferred'))->toBe(0);

        if ($refused instanceof InvalidCompanyAttribute) {
            expect($refused->attribute)->toBe('target');
        }
    })->with([
        'another store\'s' => [fn () => B2BFixtures::companyTypes('eg')[0]->id(), InvalidCompanyAttribute::class],
        'an inactive one' => [function () {
            $type = B2BFixtures::companyTypes()[0];
            B2BFixtures::deactivate($type);

            return $type->id();
        }, CompanyTypeInactive::class],
    ]);

    it('moves in B2B\'s one lock order: the store\'s lists, then each account in account order, rows never locked', function () {
        [$to, $from] = B2BFixtures::companyTypes();
        $accounts = array_map(static fn (): string => B2BFixtures::verifiedCompanyAccount(), range(1, 3));
        sort($accounts);

        foreach (array_reverse($accounts) as $customerId) {
            B2BFixtures::approved($customerId);
        }

        staffTypesAdmin([B2BPermissions::COMPANY_TRANSFER_TYPE]);
        $locks = B2BFixtures::accountLocks();
        $rowLocks = new ArrayObject;
        DB::listen(static function ($query) use ($rowLocks): void {
            if (preg_match('/"?(company|document)_types"?.*\bfor\s+(update|share|no key update|key share)\b/is', $query->sql) === 1) {
                $rowLocks[] = $query->sql;
            }
        });

        app(TransferCompanyTypeHandler::class)->handle(new TransferCompanyType($from->id(), $to->id()));

        expect(array_map(static fn (array $lock): array => [$lock[1], $lock[2]], $locks->getArrayCopy()))
            ->toBe([['b2b:types:'.Fx::storeId('sa'), 2], ...array_map(static fn (string $customerId): array => ['b2b:account:'.$customerId, 2], $accounts)])
            ->and($rowLocks->getArrayCopy())->toBe([]);
    });
});

describe('the locks (lesson 37)', function () {
    it('takes the store\'s type lock inside the change\'s own transaction, for every change to a list', function (Closure $type, Closure $change) {
        $id = $type();
        staffTypesAdmin();
        $locks = B2BFixtures::accountLocks();

        $change($id);

        expect($locks->getArrayCopy())->toContain(['exclusive', 'b2b:types:'.Fx::storeId('sa'), 2]);
    })->with(staffTypesOnAType());

    it('takes the store\'s type lock for "Reviewed" too', function () {
        staffTypesAdmin();
        $locks = B2BFixtures::accountLocks();

        app(MarkTypeListsReviewedHandler::class)->handle(new MarkTypeListsReviewed(Fx::storeId('sa')));

        expect($locks->getArrayCopy())->toBe([['exclusive', 'b2b:types:'.Fx::storeId('sa'), 2]]);
    });

    it('never row-locks a type: the store\'s lock already serialises its writers, and a row lock would deadlock with a company saving a draft that points at it (the review of step 4)', function (Closure $type, Closure $change) {
        $id = $type();
        staffTypesAdmin();
        $rowLocks = new ArrayObject;
        DB::listen(static function ($query) use ($rowLocks): void {
            if (preg_match('/"?(company|document)_types"?.*\bfor\s+(update|share|no key update|key share)\b/is', $query->sql) === 1) {
                $rowLocks[] = $query->sql;
            }
        });

        $change($id);

        expect($rowLocks->getArrayCopy())->toBe([]);
    })->with(staffTypesOnAType());

    it('replaces in B2B\'s one lock order: the store\'s lists, then each account in account order', function () {
        // The accounts first, then their companies in the reverse order, so the rows sit in the table
        // in an order the account order is not (the review of step 4).
        $accounts = array_map(static fn (): string => B2BFixtures::verifiedCompanyAccount(), range(1, 3));
        sort($accounts);

        foreach (array_reverse($accounts) as $customerId) {
            B2BFixtures::approved($customerId);
        }

        $holders = array_map(static fn (string $customerId): string => 'b2b:account:'.$customerId, $accounts);
        staffTypesAdmin();
        $locks = B2BFixtures::accountLocks();

        staffTypesDeactivate(B2BFixtures::companyTypes()[1]->id(), B2BFixtures::companyTypes()[0]->id());

        expect(array_column($locks->getArrayCopy(), 1))->toBe(['b2b:types:'.Fx::storeId('sa'), ...$holders]);
    });
});
