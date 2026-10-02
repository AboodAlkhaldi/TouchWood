<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Access\Infrastructure\Messages\SecurityMail;
use Modules\Access\Infrastructure\Messages\TemporarySecurityMessages;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\B2B\Application\Account\CompanyAnonymizer;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompany;
use Modules\B2B\Application\Command\ApproveCompany\ApproveCompanyHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Application\Query\OpenMyApplicationFile\OpenMyApplicationFile;
use Modules\B2B\Application\Query\OpenMyApplicationFile\OpenMyApplicationFileHandler;
use Modules\B2B\Application\Query\ShopLine\CompanyStandings;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompany;
use Modules\B2B\Application\Query\ViewMyCompany\ViewMyCompanyHandler;
use Modules\B2B\Domain\Exception\ApplicationFileNotFound;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\StoreContext;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeBreachList::install();
    seed(PlatformSeeder::class);
});

/*
| A company per store (b2b.md amendments 18 and 19; owner, 2026-10-02): an account may hold a company
| in each store it applies in; it applies in the store it is browsing; ordering in a store needs that
| store's company approved.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

/**
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function perStoreIn(string $code, callable $work): mixed
{
    return app(StoreContext::class)->runIn(StoreId::fromString(Fx::storeId($code)), $work);
}

describe('applying in a second store', function () {
    it('starts the draft with the name and the matching company type of the company in the other store', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::approved($customerId);
        Fx::actAsCustomer($customerId);

        perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));
        $draft = app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg')) ?? throw new LogicException('No draft in Egypt.');
        $saudiType = app(CompanyTypeRepository::class)->find((string) $saudi->details()->type->typeId);
        $egyptType = collect(B2BFixtures::companyTypes('eg'))->first(fn ($type) => $type->name()->en === $saudiType?->name()->en);

        expect($draft->storeId())->toBe(Fx::storeId('eg'))
            ->and($draft->companyId())->toBeNull()
            ->and($draft->name()?->value)->toBe($saudi->details()->name->value)
            ->and($draft->type()?->typeId)->toBe($egyptType?->id())
            // Entered fresh: they belong to that country.
            ->and($draft->crNumber())->toBeNull()
            ->and($draft->taxNumber())->toBeNull()
            ->and($draft->address())->toBeNull()
            ->and($draft->documents())->toBe([]);
    });

    it('leaves the type empty when this store\'s list has no type of that name', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::approved($customerId);
        DB::table('b2b.company_types')->where('store_id', Fx::storeId('eg'))->update(['name_en' => DB::raw("'Egypt only ' || id"), 'name_ar' => DB::raw("'مصر ' || id")]);
        Fx::actAsCustomer($customerId);

        perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));

        expect(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg'))?->type())->toBeNull()
            ->and(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg'))?->name()?->value)->toBe($saudi->details()->name->value);
    });

    it('starts from an approved company before a newer one still waiting (amendment 20(b))', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::approved($customerId);
        [$emirates] = B2BFixtures::sent($customerId, 'ae');
        DB::table('b2b.companies')->where('id', $saudi->id())->update(['name' => 'Approved Name', 'created_at' => now()->subDay()]);
        DB::table('b2b.companies')->where('id', $emirates->id())->update(['name' => 'Waiting Name', 'created_at' => now()]);
        Fx::actAsCustomer($customerId);

        perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));

        expect(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg'))?->name()?->value)->toBe('Approved Name');
    });

    it('starts from the newest company when none is approved (amendment 20(b))', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::sent($customerId);
        [$emirates] = B2BFixtures::sent($customerId, 'ae');
        DB::table('b2b.companies')->where('id', $saudi->id())->update(['name' => 'Older Name', 'created_at' => now()->subDay()]);
        DB::table('b2b.companies')->where('id', $emirates->id())->update(['name' => 'Newer Name', 'created_at' => now()]);
        Fx::actAsCustomer($customerId);

        perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));

        expect(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg'))?->name()?->value)->toBe('Newer Name');
    });

    it('keeps one open application per store, so a draft in each store may be open at once', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($customerId);

        $saudi = perStoreIn('sa', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));
        $egypt = perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));

        expect($saudi)->not->toBe($egypt)
            ->and(perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft)))->toBe($egypt);
    });
});

describe('the database, behind the code', function () {
    it('refuses a second company for one account in one store, and a second open application in one store', function (string $what) {
        $customerId = B2BFixtures::companyAccount();
        [$company] = B2BFixtures::sent($customerId);

        $write = $what === 'company'
            ? fn () => DB::table('b2b.companies')->insert([...(array) DB::table('b2b.companies')->where('id', $company->id())->first(), 'id' => strtolower((string) Str::ulid())])
            : fn () => DB::table('b2b.applications')->insert(['id' => strtolower((string) Str::ulid()), 'customer_id' => $customerId, 'store_id' => Fx::storeId('sa'), 'company_id' => $company->id(), 'state' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => DB::transaction($write))->toThrow(QueryException::class, $what === 'company' ? 'companies_one_per_store' : 'applications_one_open_per_store');
    })->with(['company', 'application']);

    it('takes a second company for one account in another store', function () {
        $customerId = B2BFixtures::companyAccount();
        [$company] = B2BFixtures::sent($customerId);

        DB::table('b2b.companies')->insert([...(array) DB::table('b2b.companies')->where('id', $company->id())->first(), 'id' => strtolower((string) Str::ulid()), 'home_store_id' => Fx::storeId('eg')]);

        expect(DB::table('b2b.companies')->where('customer_id', $customerId)->count())->toBe(2);
    });
});

describe('what others are told, per store', function () {
    it('answers for the company of the store asked about, and orders only where it is approved', function () {
        $customerId = B2BFixtures::companyAccount();
        B2BFixtures::approved($customerId);
        B2BFixtures::sent($customerId, 'eg');
        $b2b = app(B2BApi::class);

        expect($b2b->isApproved($customerId, Fx::storeId('sa')))->toBeTrue()
            ->and($b2b->isApproved($customerId, Fx::storeId('eg')))->toBeFalse()
            ->and($b2b->isApproved($customerId, Fx::storeId('ae')))->toBeFalse()
            ->and($b2b->status($customerId, Fx::storeId('eg')))->toBe(CompanyStatus::Pending)
            ->and($b2b->status($customerId, Fx::storeId('ae')))->toBeNull()
            ->and($b2b->company($customerId, Fx::storeId('eg'))?->storeId)->toBe(Fx::storeId('eg'))
            ->and(array_map(static fn ($company): string => $company->storeId, $b2b->companies($customerId)))->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);
    });

    it('lets no company order in a store that is off', function () {
        $customerId = B2BFixtures::companyAccount();
        $egypt = Fx::storeId('eg');
        [$company] = B2BFixtures::approved($customerId, 'eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        expect($company->mayOrder())->toBeTrue()
            ->and(app(B2BApi::class)->isApproved($customerId, $egypt))->toBeFalse();
    });

    it('stands per store in the shop: approved here says nothing, nothing here offers applying here', function () {
        $customerId = B2BFixtures::companyAccount();
        B2BFixtures::approved($customerId);

        $saudi = app(CompanyStandings::class)->of($customerId, Fx::storeId('sa'));
        $egypt = app(CompanyStandings::class)->of($customerId, Fx::storeId('eg'));

        expect($saudi->status)->toBe(CompanyStatus::Approved)
            ->and($egypt->status)->toBeNull()
            ->and($egypt->elsewhere)->toBeTrue()
            ->and($saudi->elsewhere)->toBeFalse();
    });

    it('shows the company page of the store being browsed, with the companies elsewhere', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::approved($customerId);
        Fx::actAsCustomer($customerId);

        $view = perStoreIn('eg', fn () => app(ViewMyCompanyHandler::class)->handle(new ViewMyCompany));

        expect($view->company)->toBeNull()
            ->and($view->homeStoreId)->toBe(Fx::storeId('eg'))
            ->and(array_map(static fn ($there): string => $there->storeId, $view->elsewhere))->toBe([Fx::storeId('sa')])
            ->and($view->elsewhere[0]->status)->toBe('APPROVED')
            ->and($view->prefill?->name)->toBe($saudi->details()->name->value);
    });
});

describe('anonymizing an account', function () {
    it('reaches its company in every store', function () {
        $customerId = B2BFixtures::companyAccount();
        B2BFixtures::sent($customerId);
        B2BFixtures::sent($customerId, 'eg');

        app(CompanyAnonymizer::class)->anonymize($customerId);

        expect(DB::table('b2b.companies')->where('customer_id', $customerId)->pluck('cr_number')->unique()->count())->toBe(1)
            ->and(DB::table('b2b.companies')->where('customer_id', $customerId)->where('cr_number', '1010123456')->exists())->toBeFalse();
    });
});

describe('sending and deciding in a second store', function () {
    it('sends the draft of the store being browsed, creating that store\'s company and leaving the other alone', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::approved($customerId);
        $draft = B2BFixtures::storedDraft($customerId, null, 'eg');
        Fx::actAsCustomer($customerId);

        perStoreIn('eg', fn () => app(SubmitApplicationHandler::class)->handle(new SubmitApplication));

        $egypt = app(CompanyRepository::class)->forCustomer($customerId, Fx::storeId('eg')) ?? throw new LogicException('No company in Egypt.');

        expect($egypt->status())->toBe(CompanyStatus::Pending)
            ->and($egypt->id())->not->toBe($saudi->id())
            ->and(app(ApplicationRepository::class)->find($draft->id())?->companyId())->toBe($egypt->id())
            ->and(app(CompanyRepository::class)->forCustomer($customerId, Fx::storeId('sa'))?->status())->toBe(CompanyStatus::Approved);
    });

    it('names the company\'s store in the decision email, in the customer\'s language', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$egypt] = B2BFixtures::sent($customerId, 'eg');
        $recorder = RecordingSecurityMessages::install();
        Fx::actAsStaff(Fx::staffWith(B2BPermissions::staff(), ['eg']));

        app(ApproveCompanyHandler::class)->handle(new ApproveCompany($egypt->id()));

        expect($recorder->companyDecisionStores)->toBe([Fx::storeId('eg')]);

        Mail::fake();
        app(TemporarySecurityMessages::class)->companyApproved(app(AccessApi::class)->customer($customerId) ?? throw new LogicException('No account.'), null, Fx::storeId('eg'));

        Mail::assertSent(SecurityMail::class, function (SecurityMail $mail): bool {
            $mail->assertSeeInHtml('This is about your company in our Egypt store.');

            return true;
        });
    });
});

describe('the migration', function () {
    it('gives every application the store of its company, or the account\'s home store for a first draft', function () {
        $sentBy = B2BFixtures::companyAccount();
        [, $sent] = B2BFixtures::sent($sentBy, 'eg');
        $draftBy = B2BFixtures::companyAccount();
        $draft = B2BFixtures::storedDraft($draftBy);
        $migration = require base_path('src/Modules/B2B/Infrastructure/Persistence/Migrations/2026_10_02_200000_b2b_company_per_store.php');

        $migration->down();
        expect(DB::getSchemaBuilder()->hasColumn('b2b.applications', 'store_id'))->toBeFalse();
        $migration->up();

        expect(DB::table('b2b.applications')->where('id', $sent->id())->value('store_id'))->toBe(Fx::storeId('eg'))
            ->and(DB::table('b2b.applications')->where('id', $draft->id())->value('store_id'))->toBe(Fx::storeId('sa'));
    });
});

describe('what the review of amendment 18 found', function () {
    it('neither names nor carries over a company whose store is off (platform.md §1.6)', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::approved($customerId, 'ae');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));
        Fx::actAsCustomer($customerId);

        $page = perStoreIn('eg', fn () => app(ViewMyCompanyHandler::class)->handle(new ViewMyCompany));
        perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));

        expect($page->elsewhere)->toBe([])
            ->and($page->prefill)->toBeNull()
            ->and(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg'))?->name())->toBeNull();
    });

    it('opens a company\'s file only in the store being browsed', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [, $saudiApplication] = B2BFixtures::sent($customerId);
        $mediaId = (string) DB::table('b2b.application_documents')->where('application_id', $saudiApplication->id())->value('media_id');
        $applications = app(ApplicationRepository::class);

        expect($mediaId)->not->toBe('')
            ->and($applications->accountHolds($customerId, Fx::storeId('sa'), $mediaId))->toBeTrue()
            ->and($applications->accountHolds($customerId, Fx::storeId('eg'), $mediaId))->toBeFalse();

        Fx::actAsCustomer($customerId);
        perStoreIn('eg', fn () => app(OpenMyApplicationFileHandler::class)->handle(new OpenMyApplicationFile($mediaId)));
    })->throws(ApplicationFileNotFound::class);

    it('leaves the type empty when two types here match it by one name each (amendment 20(a))', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::approved($customerId);
        $theirs = app(CompanyTypeRepository::class)->find((string) $saudi->details()->type->typeId) ?? throw new LogicException('No type.');
        [$first, $second] = array_slice(B2BFixtures::companyTypes('eg'), 0, 2);
        DB::table('b2b.company_types')->where('store_id', Fx::storeId('eg'))->update(['name_en' => DB::raw("'Egypt only ' || id"), 'name_ar' => DB::raw("'مصر ' || id")]);
        // One shares the English name, the other the Arabic one: either would be a guess.
        DB::table('b2b.company_types')->where('id', $first->id())->update(['name_en' => $theirs->name()->en]);
        DB::table('b2b.company_types')->where('id', $second->id())->update(['name_ar' => $theirs->name()->ar]);
        Fx::actAsCustomer($customerId);

        perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));

        expect(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg'))?->type())->toBeNull();
    });

    it('takes the one type matching by a single name when it is the only one (amendment 20(a))', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$saudi] = B2BFixtures::approved($customerId);
        $theirs = app(CompanyTypeRepository::class)->find((string) $saudi->details()->type->typeId) ?? throw new LogicException('No type.');
        [$first] = B2BFixtures::companyTypes('eg');
        DB::table('b2b.company_types')->where('store_id', Fx::storeId('eg'))->update(['name_en' => DB::raw("'Egypt only ' || id"), 'name_ar' => DB::raw("'مصر ' || id")]);
        DB::table('b2b.company_types')->where('id', $first->id())->update(['name_en' => strtoupper($theirs->name()->en)]);
        Fx::actAsCustomer($customerId);

        perStoreIn('eg', fn () => app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft));

        expect(app(ApplicationRepository::class)->openFor($customerId, Fx::storeId('eg'))?->type()?->typeId)->toBe($first->id());
    });
});
