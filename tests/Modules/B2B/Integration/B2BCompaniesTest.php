<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Public\Enums\CompanyStatus;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| The company, its applications and their files, as stored (b2b.md §5, amendments 2 and 3). Every
| application here is filled in from its account's home store's lists (amendment 5).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

describe('the repositories', function () {
    it('stores a draft with its files and reads it back as it was', function () {
        $customerId = B2BFixtures::companyAccount();
        $draft = B2BFixtures::storedDraft($customerId);

        $read = app(ApplicationRepository::class)->find($draft->id());

        expect($read?->state())->toBe(ApplicationState::Draft)
            ->and($read?->name()?->value)->toBe('Al Noor Trading')
            ->and($read?->address()?->value)->toBe("King Fahd Road\nRiyadh")
            ->and($read?->documents())->toHaveCount(3)
            ->and(array_keys($read?->documents() ?? []))->toEqualCanonicalizing(array_keys($draft->documents()))
            ->and(app(ApplicationRepository::class)->openFor($customerId)?->id())->toBe($draft->id());
    });

    it('keeps an unchanged file\'s row and replaces only the one uploaded again', function () {
        $draft = B2BFixtures::storedDraft(B2BFixtures::companyAccount());
        [$first, $second] = array_keys($draft->documents());
        $rowOf = fn (string $typeId): ?string => DB::table('b2b.application_documents')->where('application_id', $draft->id())->where('document_type_id', $typeId)->value('id');
        $kept = $rowOf($first);

        $replacement = B2BFixtures::privateFile();
        $draft->attach($second, $replacement, CarbonImmutable::now());
        app(ApplicationRepository::class)->update($draft);

        expect($rowOf($first))->toBe($kept)
            ->and(app(ApplicationRepository::class)->find($draft->id())?->documents()[$second]->mediaId)->toBe($replacement)
            ->and(DB::table('b2b.application_documents')->where('application_id', $draft->id())->count())->toBe(3);
    });

    it('sends the first application: the company exists from then on, PENDING, in the account\'s home store', function () {
        $customerId = B2BFixtures::companyAccount();
        [$company, $application] = B2BFixtures::sent($customerId);

        $stored = app(CompanyRepository::class)->forCustomer($customerId);

        expect($stored?->id())->toBe($company->id())
            ->and($stored?->status())->toBe(CompanyStatus::Pending)
            ->and($stored?->homeStoreId())->toBe(Fx::storeId('sa'))
            // The type it chose is one of its home store's (amendment 5).
            ->and($stored?->details()->type->typeId)->toBe(B2BFixtures::companyTypes()[1]->id())
            ->and(app(ApplicationRepository::class)->find($application->id())?->state())->toBe(ApplicationState::Submitted)
            ->and(app(ApplicationRepository::class)->historyOf($company->id()))->toHaveCount(1);
    });

    it('stores every status change, and suspended remembers where it came from', function () {
        [$company] = B2BFixtures::sent(B2BFixtures::companyAccount());
        $staffId = Fx::staff();
        $companies = app(CompanyRepository::class);

        $company->approve($staffId, CarbonImmutable::now());
        $company->suspend($staffId, Remark::of('reason', "A transfer was reversed.\nCall us."), CarbonImmutable::now());
        $companies->update($company);

        $stored = $companies->find($company->id());

        expect($stored?->status())->toBe(CompanyStatus::Suspended)
            ->and($stored?->statusBeforeSuspension())->toBe(CompanyStatus::Approved)
            ->and($stored?->statusReason()?->value)->toBe("A transfer was reversed.\nCall us.")
            ->and($stored?->statusChangedBy())->toBe($staffId);
    });

    it('throws a draft away with its file references, and leaves the files to Platform', function () {
        $draft = B2BFixtures::storedDraft(B2BFixtures::companyAccount());
        $files = array_map(static fn ($document): string => $document->mediaId, array_values($draft->documents()));

        app(ApplicationRepository::class)->delete($draft->id());

        expect(DB::table('b2b.applications')->where('id', $draft->id())->count())->toBe(0)
            ->and(DB::table('b2b.application_documents')->where('application_id', $draft->id())->count())->toBe(0)
            ->and(DB::table('platform.media')->whereIn('id', $files)->count())->toBe(count($files));
    });

    it('lists a company\'s applications newest first', function () {
        $customerId = B2BFixtures::companyAccount();
        [$company, $first] = B2BFixtures::rejected($customerId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        $second = B2BFixtures::storedDraft($customerId, $company->id());
        $second->submit($company->id(), B2BFixtures::companyTypes(), B2BFixtures::documentTypes(), app(ApplicationRepository::class)->lastSent($company->id()), CarbonImmutable::now());
        app(ApplicationRepository::class)->update($second);

        expect(array_map(static fn (Application $each): string => $each->id(), app(ApplicationRepository::class)->historyOf($company->id())))
            ->toBe([$second->id(), $first->id()]);
    });

    it('finds nothing for an id that is not one', function () {
        expect(app(CompanyRepository::class)->find('nope'))->toBeNull()
            ->and(app(CompanyRepository::class)->forCustomer('nope'))->toBeNull()
            ->and(app(CompanyRepository::class)->holdersOf('nope'))->toBe([])
            ->and(app(ApplicationRepository::class)->openFor('nope'))->toBeNull()
            ->and(app(ApplicationRepository::class)->historyOf('nope'))->toBe([]);
    });

    it('lists the accounts whose company holds a type, in account order, and no others (step 4)', function () {
        $held = B2BFixtures::companyTypes()[1]->id();
        // The accounts first, then their companies in the reverse order, so the rows sit in the table
        // in an order the account order is not (the review of step 4).
        $holders = array_map(static fn (): string => B2BFixtures::companyAccount(), range(1, 3));
        sort($holders);

        foreach (array_reverse($holders) as $customerId) {
            B2BFixtures::sent($customerId);
        }

        // One company moved to another type since: it no longer holds this one.
        $moved = B2BFixtures::companyAccount();
        [$company] = B2BFixtures::sent($moved);
        $company->correctType(CompanyTypeChoice::listed(B2BFixtures::companyTypes()[0]->id()), B2BFixtures::companyTypes());
        app(CompanyRepository::class)->update($company);

        expect(app(CompanyRepository::class)->holdersOf($held))->toBe($holders)
            ->and(app(CompanyRepository::class)->holdersOf(B2BFixtures::companyTypes()[0]->id()))->toBe([$moved]);
    });
});

describe('what the database takes: everything the code takes', function () {
    it('stores numbers in any script, and addresses and reasons on several lines', function () {
        $customerId = B2BFixtures::companyAccount();
        $applications = app(ApplicationRepository::class);
        $draft = Application::draft($applications->nextId(), $customerId, null);
        $draft->describe(
            CompanyName::of('مؤسسة النور للتجارة'),
            CompanyTypeChoice::other('جمعية تعاونية'),
            RegistrationNumber::of('cr_number', 'س ت-١٠١٠١٢٣٤٥٦'),
            RegistrationNumber::of('tax_number', '٣٠٠١٢٣٤٥٦٧٠٠٠٠٣'),
            CompanyAddress::of("طريق الملك فهد\nالرياض ١٢٣٤٥"),
            Remark::of('note', "أرفقنا الشهادة الجديدة.\nشكرًا."),
        );
        $applications->add($draft);

        $read = $applications->find($draft->id());

        expect($read?->crNumber()?->value)->toBe('س ت-١٠١٠١٢٣٤٥٦')
            ->and($read?->taxNumber()?->value)->toBe('٣٠٠١٢٣٤٥٦٧٠٠٠٠٣')
            ->and($read?->type()?->other)->toBe('جمعية تعاونية')
            ->and($read?->note()?->value)->toBe("أرفقنا الشهادة الجديدة.\nشكرًا.");
    });
});

describe('what the database refuses on its own', function () {
    it('refuses a company row the code would never write', function (Closure $change, string $constraint) {
        [$company] = B2BFixtures::sent(B2BFixtures::companyAccount());

        expect(fn () => $change($company->id()))->toThrow(QueryException::class, $constraint);
    })->with([
        // Blocking is Access's account status, never a company status (handoff §8.2).
        'an unknown status' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['status' => 'BLOCKED']), 'companies_status'],
        'suspended, not remembering from what' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['status' => 'SUSPENDED', 'status_reason' => 'Why']), 'companies_status_before_suspension'],
        'remembering while not suspended' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['status_before_suspension' => 'APPROVED']), 'companies_status_before_suspension'],
        'suspended from suspended' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['status' => 'SUSPENDED', 'status_before_suspension' => 'SUSPENDED', 'status_reason' => 'Why']), 'companies_status_before_suspension'],
        'rejected with no reason' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['status' => 'REJECTED']), 'companies_status_reason_given'],
        'a listed type and "Other" both' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['company_type_other' => 'Cooperative']), 'companies_type_exactly_one'],
        'neither' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['company_type_id' => null]), 'companies_type_exactly_one'],
        'a blank name' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['name' => ' ']), 'companies_name_text'],
        'a name on two lines' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['name' => "Al Noor\nTrading"]), 'companies_name_text'],
        'a CR number with a slash' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['cr_number' => '1010/123']), 'companies_cr_number_text'],
        'a tab in the address' => [fn (string $id) => DB::table('b2b.companies')->where('id', $id)->update(['address' => "King Fahd Road\tRiyadh"]), 'companies_address_text'],
        'a second company for one account' => [function (string $id) {
            $row = (array) DB::table('b2b.companies')->where('id', $id)->first();
            DB::table('b2b.companies')->insert([...$row, 'id' => strtolower((string) Str::ulid())]);
        }, 'companies_customer_id_unique'],
    ]);

    it('refuses an application row the code would never write', function (Closure $change, string $constraint) {
        $customerId = B2BFixtures::companyAccount();
        [, $application] = B2BFixtures::sent($customerId);

        expect(fn () => $change($application->id(), $customerId))->toThrow(QueryException::class, $constraint);
    })->with([
        'an unknown state' => [fn (string $id) => DB::table('b2b.applications')->where('id', $id)->update(['state' => 'WITHDRAWN']), 'applications_state'],
        'sent without an address' => [fn (string $id) => DB::table('b2b.applications')->where('id', $id)->update(['address' => null]), 'applications_sent_complete'],
        'sent with no company' => [fn (string $id) => DB::table('b2b.applications')->where('id', $id)->update(['company_id' => null]), 'applications_sent_complete'],
        'decided by nobody' => [fn (string $id) => DB::table('b2b.applications')->where('id', $id)->update(['state' => 'APPROVED', 'decided_at' => now()]), 'applications_decided_together'],
        'rejected with no reason' => [fn (string $id) => DB::table('b2b.applications')->where('id', $id)->update(['state' => 'REJECTED', 'decided_at' => now(), 'decided_by' => Fx::staff()]), 'applications_rejection_reason_given'],
        'a draft holding both kinds of type' => [fn (string $id) => DB::table('b2b.applications')->where('id', $id)->update(['state' => 'DRAFT', 'company_type_other' => 'Cooperative']), 'applications_type_at_most_one'],
        'a tab in the note' => [fn (string $id) => DB::table('b2b.applications')->where('id', $id)->update(['note' => "Attached\tagain"]), 'applications_note_text'],
        'a second open application for one account' => [fn (string $id, string $customerId) => DB::table('b2b.applications')->insert([
            'id' => strtolower((string) Str::ulid()), 'customer_id' => $customerId, 'state' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ]), 'applications_one_open_per_customer'],
        'two files under one type' => [function (string $id) {
            $typeId = DB::table('b2b.application_documents')->where('application_id', $id)->value('document_type_id');
            DB::table('b2b.application_documents')->insert(['id' => strtolower((string) Str::ulid()), 'application_id' => $id, 'document_type_id' => $typeId, 'media_id' => B2BFixtures::privateFile(), 'uploaded_at' => now()]);
        }, 'application_documents_one_per_type'],
        'deleting a file an application holds' => [fn (string $id) => DB::table('platform.media')->where('id', DB::table('b2b.application_documents')->where('application_id', $id)->value('media_id'))->delete(), 'application_documents_media_id_foreign'],
    ]);

    it('lets an account start a new draft once the last application is decided', function () {
        $customerId = B2BFixtures::companyAccount();
        [$company] = B2BFixtures::rejected($customerId);

        // Only an open one blocks another: reapplication is unlimited once the last was decided.
        $next = B2BFixtures::storedDraft($customerId, $company->id());

        expect(app(ApplicationRepository::class)->openFor($customerId)?->id())->toBe($next->id());
    });
});
