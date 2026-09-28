<?php

declare(strict_types=1);

namespace Tests\Modules\B2B\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
