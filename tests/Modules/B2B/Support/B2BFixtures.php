<?php

declare(strict_types=1);

namespace Tests\Modules\B2B\Support;

use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\B2B\Application\Types\GiveEveryStoreTheStartingTypes;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/**
 * Companies, applications and their files for B2B tests — kept here, not as global functions, so
 * two test files can never declare the same name. Accounts register in the 'sa' store, so their
 * home store's lists are that store's.
 */
final class B2BFixtures
{
    /**
     * Every store's starting lists. Seeding already wrote them through the StoreCreated listener,
     * so this writes nothing where they exist.
     */
    public static function startingTypes(): void
    {
        app(GiveEveryStoreTheStartingTypes::class)->run();
    }

    /**
     * @return list<CompanyType> every company type of that store, active or not
     */
    public static function companyTypes(string $storeCode = 'sa'): array
    {
        return app(CompanyTypeRepository::class)->all(Fx::storeId($storeCode));
    }

    /**
     * @return list<DocumentType> every document type of that store, active or not
     */
    public static function documentTypes(string $storeCode = 'sa'): array
    {
        return app(DocumentTypeRepository::class)->all(Fx::storeId($storeCode));
    }

    /**
     * A company account registered in the 'sa' store.
     */
    public static function companyAccount(): string
    {
        return Fx::customer(strtolower((string) Str::ulid()).'@example.test', 'sa', 'company');
    }

    /**
     * A private file, as Platform stores one: a company's papers are never public (b2b.md §1.4).
     */
    public static function privateFile(): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('platform.media')->insert([
            'id' => $id,
            'visibility' => 'PRIVATE',
            'disk' => 'local',
            'object_key' => 'media/'.$id.'.pdf',
            'original_filename' => 'certificate.pdf',
            'mime' => 'application/pdf',
            'bytes' => 120_000,
            'checksum' => hash('sha256', $id),
        ]);

        return $id;
    }

    /**
     * A draft, filled in with the home store's second company type and a file under each of its
     * active document types, stored.
     */
    public static function storedDraft(string $customerId, ?string $companyId = null): Application
    {
        $applications = app(ApplicationRepository::class);
        $draft = Application::draft($applications->nextId(), $customerId, $companyId);
        $draft->describe(
            CompanyName::of('Al Noor Trading'),
            CompanyTypeChoice::listed(app(CompanyTypeRepository::class)->active(Fx::storeId('sa'))[1]->id()),
            RegistrationNumber::of('cr_number', '1010123456'),
            RegistrationNumber::of('tax_number', '300123456700003'),
            CompanyAddress::of("King Fahd Road\nRiyadh"),
            null,
        );

        foreach (app(DocumentTypeRepository::class)->active(Fx::storeId('sa')) as $type) {
            $draft->attach($type->id(), self::privateFile(), CarbonImmutable::now());
        }

        $applications->add($draft);

        return $draft;
    }

    /**
     * The whole first application: sent, and the company it creates.
     *
     * @return array{0: Company, 1: Application}
     */
    public static function sent(string $customerId): array
    {
        $draft = self::storedDraft($customerId);
        $companies = app(CompanyRepository::class);
        $companyId = $companies->nextId();

        $details = $draft->submit($companyId, self::companyTypes(), self::documentTypes(), null, CarbonImmutable::now());
        $company = Company::fromFirstApplication($companyId, $customerId, Fx::storeId('sa'), $details, CarbonImmutable::now());
        $companies->add($company);
        app(ApplicationRepository::class)->update($draft);

        return [$company, $draft];
    }

    /**
     * The whole first application: sent and approved, and its company approved with it.
     *
     * @return array{0: Company, 1: Application}
     */
    public static function approved(string $customerId): array
    {
        [$company, $application] = self::sent($customerId);
        $staffId = Fx::staff();

        $application->approve($staffId, null, CarbonImmutable::now());
        app(ApplicationRepository::class)->update($application);
        $company->approve($staffId, CarbonImmutable::now());
        app(CompanyRepository::class)->update($company);

        return [$company, $application];
    }

    /**
     * A company account registered in the 'sa' store whose email address is confirmed — what
     * sending an application needs (b2b.md §1.2). Its phone is not.
     */
    public static function verifiedCompanyAccount(): string
    {
        $customerId = self::companyAccount();
        DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => CarbonImmutable::now()]);

        return $customerId;
    }

    /**
     * A real PDF on local disk, as a person's browser sends one, for the use cases that upload.
     */
    public static function pdf(): string
    {
        File::ensureDirectoryExists(self::uploads());
        $path = self::uploads().'/'.uniqid('', true).'-paper.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%".uniqid('', true)."\n%%EOF\n");

        return $path;
    }

    /**
     * Where pdf() writes; a test file removes it after each test.
     */
    public static function uploads(): string
    {
        return sys_get_temp_dir().'/tw-b2b-uploads';
    }

    /**
     * Staff suspending the company, straight through the domain (b2b.md §4.1); the use case is
     * SuspendCompany (step 4).
     */
    public static function suspend(Company $company): void
    {
        $company->suspend(Fx::staff(), Remark::of('reason', 'Suspended while the tax number is checked.'), CarbonImmutable::now());
        app(CompanyRepository::class)->update($company);
    }

    /**
     * Staff deactivating a type, straight through the domain (b2b.md §1.3): shown greyed out, or
     * hidden. The use cases are DeactivateCompanyType and DeactivateDocumentType (step 4).
     */
    public static function deactivate(CompanyType|DocumentType $type, InactiveTypeDisplay $shown = InactiveTypeDisplay::Hidden): void
    {
        $type->deactivate($shown);

        $type instanceof CompanyType
            ? app(CompanyTypeRepository::class)->update($type)
            : app(DocumentTypeRepository::class)->update($type);
    }

    /**
     * Records, for every audit entry written from now on, its action and the transaction level it
     * was written at — so a test can tell an entry written inside its use case's own transaction
     * from one the test's RefreshDatabase transaction merely satisfied (lesson 87).
     *
     * @return ArrayObject<int, array{0: string, 1: int}> filled as entries are written
     */
    public static function auditLevels(): ArrayObject
    {
        /** @var ArrayObject<int, array{0: string, 1: int}> $levels */
        $levels = new ArrayObject;

        DB::listen(static function (QueryExecuted $query) use ($levels): void {
            if (! str_starts_with($query->sql, 'insert into "platform"."audit_entries"')) {
                return;
            }

            foreach ($query->bindings as $binding) {
                if (is_string($binding) && preg_match('/\A[a-z0-9_]+\.[a-z0-9_]+\.[a-z0-9_]+\z/', $binding) === 1) {
                    $levels[] = [$binding, DB::transactionLevel()];

                    return;
                }
            }
        });

        return $levels;
    }

    /**
     * Records, for every advisory lock asked for from now on, whether it is exclusive or shared, its
     * key, and the transaction level at the moment — so a test tells a lock taken inside a use case's
     * own transaction (level 2 under RefreshDatabase) from one missing, or taken where the lock would
     * end with the statement (the review of step 3b).
     *
     * @return ArrayObject<int, array{0: string, 1: string, 2: int}>
     */
    public static function accountLocks(): ArrayObject
    {
        /** @var ArrayObject<int, array{0: string, 1: string, 2: int}> $locks */
        $locks = new ArrayObject;

        DB::listen(static function (QueryExecuted $query) use ($locks): void {
            if (preg_match('/pg_advisory_xact_lock(_shared)?\(/', $query->sql, $match) !== 1) {
                return;
            }

            $locks[] = [($match[1] ?? '') === '_shared' ? 'shared' : 'exclusive', (string) ($query->bindings[0] ?? ''), DB::transactionLevel()];
        });

        return $locks;
    }

    /**
     * Counts every file Platform starts to store from now on — even one a rolled-back transaction
     * then takes away. A refusal "before anything is stored" leaves it at zero, where an upload that
     * came first and was undone would not (3a critic M5).
     *
     * @return ArrayObject<int, true>
     */
    public static function mediaWrites(): ArrayObject
    {
        /** @var ArrayObject<int, true> $writes */
        $writes = new ArrayObject;

        DB::listen(static function (QueryExecuted $query) use ($writes): void {
            // Platform writes a media row with its own SQL (INSERT … ON CONFLICT), not the query
            // builder's quoted form, so both are matched.
            if (preg_match('/\A\s*insert\s+into\s+"?platform"?\."?media"?[\s(]/i', $query->sql) === 1) {
                $writes[] = true;
            }
        });

        return $writes;
    }

    /**
     * The first application, sent and rejected with these flags and requests, and its company
     * rejected with it.
     *
     * @param  list<ApplicationFlag>  $flags
     * @param  list<ApplicationRequest>  $requests
     * @return array{0: Company, 1: Application}
     */
    public static function rejected(string $customerId, array $flags = [], array $requests = []): array
    {
        [$company, $application] = self::sent($customerId);
        $staffId = Fx::staff();
        $reason = Remark::of('reason', 'The CR number does not match the certificate.');

        $application->reject($staffId, $reason, CarbonImmutable::now(), $flags, $requests);
        app(ApplicationRepository::class)->update($application);
        $company->reject($staffId, $reason, CarbonImmutable::now());
        app(CompanyRepository::class)->update($company);

        return [$company, $application];
    }
}
