<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseApplicationRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseCompanyRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseCompanyTypeRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseDocumentTypeRepository;
use Modules\B2B\Infrastructure\Eloquent\DatabaseStoreTypeListsRepository;
use Modules\B2B\Infrastructure\Listener\WriteStartingTypes;
use Modules\B2B\Infrastructure\Media\ApplicationFilesUsage;
use Modules\Platform\Public\Contracts\MediaUsages;
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'b2b');

        // A company's papers are Platform media, and every application holding one blocks its
        // delete (b2b.md §1.4).
        $this->app->make(MediaUsages::class)->register('b2b', ApplicationFilesUsage::class);

        // A store opened later starts with the same type lists as the others, until its admins
        // change them (amendment 6(a)).
        Event::listen(StoreCreated::class, [WriteStartingTypes::class, 'handle']);
    }
}
