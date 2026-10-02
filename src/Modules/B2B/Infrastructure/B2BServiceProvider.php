<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Contracts\ShopperLines;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\B2B\Application\B2BApiImpl;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Query\ListCompanies\CompanyReader;
use Modules\B2B\Application\Query\ShopLine\CompanyStandings;
use Modules\B2B\Application\Query\ViewTypeLists\TypeHolders;
use Modules\B2B\Application\Settings\BankAccountSettings;
use Modules\B2B\Application\Settings\FormRules;
use Modules\B2B\Domain\Repository\ApplicationReferenceCounter;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseApplicationReferenceCounter;
use Modules\B2B\Infrastructure\Eloquent\DatabaseApplicationRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseCompanyReader;
use Modules\B2B\Infrastructure\Eloquent\DatabaseCompanyRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseCompanyStandings;
use Modules\B2B\Infrastructure\Eloquent\DatabaseCompanyTypeRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseDocumentTypeRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseStoreTypeListsRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseTypeHolders;
use Modules\B2B\Infrastructure\Listener\AnonymizeCompany;
use Modules\B2B\Infrastructure\Listener\WriteStartingTypes;
use Modules\B2B\Infrastructure\Media\ApplicationFilesUsage;
use Modules\B2B\Infrastructure\Settings\BankTransferLine;
use Modules\B2B\Presentation\Storefront\CompanyShopperLine;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Contracts\SettingsSectionLines;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\Events\StoreCreated;

/**
 * The company behind a company account (b2b.md). Registered after Access, which it depends on
 * (handoff §4.4).
 */
final class B2BServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompanyTypeRepository::class, DatabaseCompanyTypeRepository::class);
        $this->app->bind(DocumentTypeRepository::class, DatabaseDocumentTypeRepository::class);
        $this->app->bind(StoreTypeListsRepository::class, DatabaseStoreTypeListsRepository::class);
        $this->app->bind(CompanyRepository::class, DatabaseCompanyRepository::class);
        $this->app->bind(ApplicationRepository::class, DatabaseApplicationRepository::class);
        $this->app->bind(ApplicationReferenceCounter::class, DatabaseApplicationReferenceCounter::class);
        $this->app->bind(CompanyReader::class, DatabaseCompanyReader::class);
        $this->app->bind(CompanyStandings::class, DatabaseCompanyStandings::class);
        $this->app->bind(TypeHolders::class, DatabaseTypeHolders::class);
        $this->app->bind(B2BApi::class, B2BApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'b2b');

        // B2B sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('b2b', ...B2BPermissions::definitions());

        // A company's papers are Platform media, and every application holding one blocks its
        // delete (b2b.md §1.4).
        $this->app->make(MediaUsages::class)->register('b2b', ApplicationFilesUsage::class);

        // The bank account an approved company transfers to, one per store (amendment 12(b)).
        $this->app->make(SettingsRegistry::class)->define('b2b', ...BankAccountSettings::definitions());
        // The company form's minimums, one set for every store (amendment 16(b)).
        $this->app->make(SettingsRegistry::class)->define('b2b', ...FormRules::definitions());
        // Bank transfer is on only while all three are filled in; the section says which (13(c)).
        $this->app->make(SettingsSectionLines::class)->register('b2b', BankTransferLine::class);

        // A store opened later starts with the same type lists as the others, until its admins
        // change them (amendment 6(a)).
        Event::listen(StoreCreated::class, [WriteStartingTypes::class, 'handle']);

        // An anonymized account takes its company's personal fields and papers with it, and any
        // unsent draft (amendment 12(a)) — from the queue (13(a)).
        Event::listen(CustomerAnonymized::class, [AnonymizeCompany::class, 'handle']);

        // The company's own page (amendment 14): in the account's side list for company accounts,
        // and a line under the shop's header while the company cannot order (access.md amendment 50).
        $this->app->make(CustomerAccountPages::class)->register(
            new CustomerAccountPageDto('b2b', 'company', CompanyShopperLine::ROUTE, AccountType::Company),
        );
        $this->app->make(ShopperLines::class)->register(CompanyShopperLine::class);

        /*
        | The staff screens (step 7, b2b.md §4.6, amendment 19), in the menu's Companies group. An
        | entry is offered for one permission: the type lists for each list's update job, which is
        | provisional (19(b)) — a holder of only another job on a list opens it by its address. What is
        | offered is never what is allowed: every handler behind these asks again (handoff §19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('b2b', 'companies', 'companies', 'b2b.admin.companies', B2BPermissions::COMPANY_VIEW, 10, icon: 'companies'),
            new MenuEntryDto('b2b', 'company_types', 'companies', 'b2b.admin.company-types', B2BPermissions::COMPANY_TYPE_UPDATE, 20, icon: 'company_types'),
            new MenuEntryDto('b2b', 'document_types', 'companies', 'b2b.admin.document-types', B2BPermissions::DOCUMENT_TYPE_UPDATE, 30, icon: 'document_types'),
        );

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/routes.php');
            // The panel's own file, apart: the admin session cookie must be set before "web" opens a
            // session, so it brings its own middleware rather than sharing the shop's group.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
