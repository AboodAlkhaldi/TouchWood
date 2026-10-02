<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Query\StaffCompanyActions\StaffCompanyActions;
use Modules\B2B\Application\Query\StaffCompanyActions\StaffCompanyActionsForReader;
use Modules\B2B\Application\Query\ViewTypeLists\TypeHolders;
use Modules\B2B\Application\Query\ViewTypeLists\TypeListsView;
use Modules\B2B\Application\Query\ViewTypeLists\ViewTypeLists;
use Modules\B2B\Application\Query\ViewTypeLists\ViewTypeListsHandler;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 7: the two reads the staff screens needed and no query answered (b2b.md §4.6, amendment
| 19) — one store's type list with what the reader may do to it, and what the reader may do next to
| one company. The screens show what these answer; every handler behind a button asks again.
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
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function staffScreenQueriesAs(array $permissions, array $stores = ['sa']): string
{
    $staffId = Fx::staffWith($permissions, $stores);
    Fx::actAsStaff($staffId);

    return $staffId;
}

function staffScreenQueriesList(string $kind, ?string $storeCode = 'sa'): TypeListsView
{
    return app(ViewTypeListsHandler::class)->handle(new ViewTypeLists($storeCode === null ? null : Fx::storeId($storeCode), $kind));
}

function staffScreenQueriesActions(string $companyId): StaffCompanyActions
{
    return app(StaffCompanyActionsForReader::class)->forCompany($companyId);
}

/**
 * A company that sent its first application and waits for a decision, in the 'sa' store.
 *
 * @return array{0: string, 1: string} the company id and its account id
 */
function staffScreenQueriesWaiting(): array
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::sent($customerId);

    return [$company->id(), $customerId];
}

describe('one store\'s type list (amendment 19(c))', function () {
    it('is read by anyone holding any job on that list in that store — moving companies alone included', function () {
        staffScreenQueriesAs([B2BPermissions::COMPANY_TRANSFER_TYPE]);

        $view = staffScreenQueriesList(ViewTypeLists::COMPANY);

        expect($view->kind)->toBe('company')
            ->and($view->storeId)->toBe(Fx::storeId('sa'))
            ->and(array_map(static fn ($type): string => $type->nameEn, $view->types))
            ->toBe(array_map(static fn ($type): string => $type->name()->en, B2BFixtures::companyTypes()))
            // Reading is not a job: only moving companies is offered.
            ->and($view->actions->mayTransfer)->toBeTrue()
            ->and($view->actions->mayAdd)->toBeFalse()
            ->and($view->actions->mayUpdate)->toBeFalse()
            ->and($view->actions->mayDeactivate)->toBeFalse()
            ->and($view->actions->mayMarkReviewed)->toBeFalse()
            ->and($view->actions->mayReadCompanyTypes)->toBeTrue()
            ->and($view->actions->mayReadDocumentTypes)->toBeFalse();
    });

    it('refuses someone holding no job on that list anywhere, though they hold one on the other list', function () {
        staffScreenQueriesAs([B2BPermissions::DOCUMENT_TYPE_UPDATE, B2BPermissions::COMPANY_VIEW]);

        expect(fn () => staffScreenQueriesList(ViewTypeLists::COMPANY))->toThrow(Unauthorized::class)
            ->and(staffScreenQueriesList(ViewTypeLists::DOCUMENT)->kind)->toBe('document')
            // Refused by its name before the store is even read: what is not a store is not
            // weighed for someone who may not read the list anywhere.
            ->and(fn () => app(ViewTypeListsHandler::class)->handle(new ViewTypeLists('not-a-store', ViewTypeLists::COMPANY)))->toThrow(Unauthorized::class);
    });

    it('refuses a store the reader holds no job on that list in — the store the panel names is theirs to name', function () {
        staffScreenQueriesAs([B2BPermissions::COMPANY_TYPE_UPDATE], ['eg']);

        expect(fn () => staffScreenQueriesList(ViewTypeLists::COMPANY, 'sa'))->toThrow(Unauthorized::class)
            ->and(staffScreenQueriesList(ViewTypeLists::COMPANY, 'eg')->storeId)->toBe(Fx::storeId('eg'));
    });

    it('refuses a reader with no store, and anything that is not a store', function () {
        staffScreenQueriesAs([B2BPermissions::COMPANY_TYPE_UPDATE]);

        expect(fn () => staffScreenQueriesList(ViewTypeLists::COMPANY, null))->toThrow(Unauthorized::class)
            ->and(fn () => app(ViewTypeListsHandler::class)->handle(new ViewTypeLists('not-a-store', ViewTypeLists::COMPANY)))->toThrow(InvalidCompanyAttribute::class);
    });

    it('refuses a store that does not exist to a Super Admin, who covers every store', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(ViewTypeListsHandler::class)->handle(new ViewTypeLists('01jzzzzzzzzzzzzzzzzzzzzzzz', ViewTypeLists::COMPANY)))
            ->toThrow(InvalidCompanyAttribute::class);
    });

    it('counts the companies holding each company type of the store, and no other store\'s', function () {
        [$companyId] = staffScreenQueriesWaiting();
        staffScreenQueriesWaiting();
        $held = (string) DB::table('b2b.companies')->where('id', $companyId)->value('company_type_id');
        // A company of another store, on another store's type: not this list's.
        [$elsewhere] = staffScreenQueriesWaiting();
        DB::table('b2b.companies')->where('id', $elsewhere)->update(['home_store_id' => Fx::storeId('eg'), 'company_type_id' => B2BFixtures::companyTypes('eg')[1]->id()]);
        staffScreenQueriesAs([B2BPermissions::COMPANY_TYPE_UPDATE]);

        $holders = [];
        foreach (staffScreenQueriesList(ViewTypeLists::COMPANY)->types as $type) {
            $holders[$type->id] = $type->holders;
        }

        expect($holders[$held])->toBe(2)
            ->and(array_sum($holders))->toBe(2)
            ->and(count(array_filter($holders, static fn (?int $count): bool => $count === 0)))->toBe(count($holders) - 1)
            // The one query itself: the other store's type is not among this store's counts.
            ->and(app(TypeHolders::class)->countsFor(Fx::storeId('sa')))->toBe([$held => 2])
            ->and(app(TypeHolders::class)->countsFor(Fx::storeId('eg')))->toBe([B2BFixtures::companyTypes('eg')[1]->id() => 1]);
    });

    it('shows a document type\'s required switch and a deactivated type\'s look, and no holders', function () {
        $paper = B2BFixtures::documentTypes()[0];
        B2BFixtures::deactivate($paper, InactiveTypeDisplay::Greyed);
        staffScreenQueriesAs([B2BPermissions::DOCUMENT_TYPE_DEACTIVATE]);

        $view = staffScreenQueriesList(ViewTypeLists::DOCUMENT);
        $row = array_values(array_filter($view->types, static fn ($type): bool => $type->id === $paper->id()))[0];

        expect($row->active)->toBeFalse()
            ->and($row->inactiveDisplay)->toBe('GREYED')
            ->and($row->required)->toBeTrue()
            ->and($row->holders)->toBeNull()
            ->and($view->actions->mayDeactivate)->toBeTrue()
            ->and($view->actions->mayAdd)->toBeFalse();
    });

    it('says whether the store\'s lists are still marked copied, and who may mark them reviewed', function () {
        staffScreenQueriesAs([B2BPermissions::COMPANY_TYPE_CREATE, B2BPermissions::DOCUMENT_TYPE_UPDATE]);

        $view = staffScreenQueriesList(ViewTypeLists::COMPANY);
        expect($view->copiedNotReviewed)->toBeTrue()
            // Either list's update job clears the notice (10(d)), whichever list is shown.
            ->and($view->actions->mayMarkReviewed)->toBeTrue()
            ->and($view->actions->mayReadDocumentTypes)->toBeTrue();

        DB::table('b2b.store_type_lists')->where('store_id', Fx::storeId('sa'))->update(['copied_not_reviewed' => false]);

        expect(staffScreenQueriesList(ViewTypeLists::COMPANY)->copiedNotReviewed)->toBeFalse();
    });

    it('offers deactivating into a new type only to someone who may add types as well (amendment 11(b))', function () {
        staffScreenQueriesAs([B2BPermissions::COMPANY_TYPE_DEACTIVATE]);
        expect(staffScreenQueriesList(ViewTypeLists::COMPANY)->actions->mayDeactivateIntoNew)->toBeFalse()
            // Moving companies between types is a job of its own (amendment 11(c)).
            ->and(staffScreenQueriesList(ViewTypeLists::COMPANY)->actions->mayTransfer)->toBeFalse();

        staffScreenQueriesAs([B2BPermissions::COMPANY_TYPE_DEACTIVATE, B2BPermissions::COMPANY_TYPE_CREATE]);
        expect(staffScreenQueriesList(ViewTypeLists::COMPANY)->actions->mayDeactivateIntoNew)->toBeTrue();

        // A document type has no holders to move.
        staffScreenQueriesAs([B2BPermissions::DOCUMENT_TYPE_DEACTIVATE, B2BPermissions::DOCUMENT_TYPE_CREATE]);
        expect(staffScreenQueriesList(ViewTypeLists::DOCUMENT)->actions->mayDeactivateIntoNew)->toBeFalse();
    });
});

describe('what the reader may do next to one company (§4.6)', function () {
    it('offers approving and rejecting a waiting application to a reviewer of its store, and nothing else', function () {
        [$companyId] = staffScreenQueriesWaiting();
        staffScreenQueriesAs([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW]);

        $may = staffScreenQueriesActions($companyId);

        expect($may->mayApprove)->toBeTrue()
            ->and($may->mayReject)->toBeTrue()
            ->and($may->approveRefusal)->toBeNull()
            ->and($may->maySuspend)->toBeFalse()
            ->and($may->mayReinstate)->toBeFalse()
            ->and($may->mayCorrectType)->toBeFalse()
            ->and($may->mayOpenDocuments)->toBeFalse()
            ->and($may->typeChoices)->toBe([]);
    });

    it('offers nothing for a company of a store the reader does not cover, however many jobs they hold', function () {
        [$companyId] = staffScreenQueriesWaiting();
        staffScreenQueriesAs(B2BPermissions::staff(), ['eg']);

        expect(staffScreenQueriesActions($companyId))->toEqual(StaffCompanyActions::none())
            ->and(staffScreenQueriesActions('01jzzzzzzzzzzzzzzzzzzzzzzz'))->toEqual(StaffCompanyActions::none());
    });

    it('offers no decision once the application is decided', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::approved($customerId);
        staffScreenQueriesAs(B2BPermissions::staff());

        $may = staffScreenQueriesActions($company->id());

        expect($may->mayApprove)->toBeFalse()
            ->and($may->mayReject)->toBeFalse()
            ->and($may->maySuspend)->toBeTrue()
            ->and($may->mayReinstate)->toBeFalse()
            // An approved company is never corrected to "Other" (amendment 13(b)).
            ->and($may->mayCorrectType)->toBeTrue()
            ->and($may->mayChooseOther)->toBeFalse()
            ->and($may->mayOpenDocuments)->toBeTrue();
    });

    it('says why approving would be refused: a company still "Other", then an erased account', function () {
        [$companyId, $customerId] = staffScreenQueriesWaiting();
        staffScreenQueriesAs([B2BPermissions::COMPANY_REVIEW]);

        DB::table('b2b.companies')->where('id', $companyId)->update(['company_type_id' => null, 'company_type_other' => 'Trading house']);
        expect(staffScreenQueriesActions($companyId)->approveRefusal)->toBe(StaffCompanyActions::APPROVE_TYPE_NOT_SET);

        $listed = B2BFixtures::companyTypes()[0]->id();
        DB::table('b2b.companies')->where('id', $companyId)->update(['company_type_id' => $listed, 'company_type_other' => null]);
        expect(staffScreenQueriesActions($companyId)->approveRefusal)->toBeNull();

        DB::table('access.customers')->where('id', $customerId)->update(['anonymized_at' => now()]);
        expect(staffScreenQueriesActions($companyId)->approveRefusal)->toBe(StaffCompanyActions::APPROVE_ACCOUNT_DELETED);
    });

    it('offers reinstating, and neither suspending nor a type correction, while suspended', function () {
        [$companyId] = staffScreenQueriesWaiting();
        B2BFixtures::suspend(app(CompanyRepository::class)->find($companyId) ?? throw new LogicException);
        staffScreenQueriesAs(B2BPermissions::staff());

        $may = staffScreenQueriesActions($companyId);

        expect($may->mayReinstate)->toBeTrue()
            ->and($may->maySuspend)->toBeFalse()
            // A suspended company's type is never changed by staff (10(h)); and nothing waits to decide.
            ->and($may->mayCorrectType)->toBeFalse()
            ->and($may->typeChoices)->toBe([])
            ->and($may->mayApprove)->toBeFalse();
    });

    it('offers a deactivated type for a correction only to someone who may activate it again (amendment 10(b))', function () {
        [$companyId] = staffScreenQueriesWaiting();
        $gone = B2BFixtures::companyTypes()[3];
        B2BFixtures::deactivate($gone);

        staffScreenQueriesAs([B2BPermissions::COMPANY_CORRECT_TYPE]);
        $choices = staffScreenQueriesActions($companyId)->typeChoices;
        expect(array_map(static fn ($choice): string => $choice->id, $choices))->not->toContain($gone->id())
            ->and(count($choices))->toBe(count(B2BFixtures::companyTypes()) - 1)
            ->and(staffScreenQueriesActions($companyId)->mayChooseOther)->toBeTrue();

        staffScreenQueriesAs([B2BPermissions::COMPANY_CORRECT_TYPE, B2BPermissions::COMPANY_TYPE_DEACTIVATE]);
        $offered = array_values(array_filter(staffScreenQueriesActions($companyId)->typeChoices, static fn ($choice): bool => $choice->id === $gone->id()));
        expect($offered)->toHaveCount(1)
            ->and($offered[0]->active)->toBeFalse();
    });

    it('names the stores the list may be filtered by: the reader\'s own, every one for a Super Admin', function () {
        staffScreenQueriesAs([B2BPermissions::COMPANY_VIEW], ['sa', 'eg']);
        expect(app(StaffCompanyActionsForReader::class)->listStores())->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(app(StaffCompanyActionsForReader::class)->listStores())->toBeNull();
    });
});
