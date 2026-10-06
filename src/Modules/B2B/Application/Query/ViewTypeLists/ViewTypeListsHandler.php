<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewTypeLists;

use InvalidArgumentException;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **One store's type list, as staff manage it** (b2b.md §1.3, §4.6, amendment 21): its company types
 * or its document types, active or not, in the form's order; the store's "copied, not yet reviewed"
 * notice (amendment 6(a)); for company types, how many companies hold each; and what the reader may
 * do to the list.
 *
 * **Reading a list is part of every job on it** [PROVISIONAL, amendment 21(c)]: whoever holds, in that
 * store, any job on that list may read it — no permission of its own. Someone holding none of them
 * anywhere is refused by its name before anything is read; a store they do not hold one in is
 * refused as not allowed, as a store they name always is (§3.2). A store that does not exist is
 * `InvalidCompanyAttribute`, as for adding a type to one.
 */
final readonly class ViewTypeListsHandler
{
    /** Every job on the company types (amendments 10 and 11(c)). */
    public const array COMPANY_JOBS = [
        B2BPermissions::COMPANY_TYPE_CREATE,
        B2BPermissions::COMPANY_TYPE_UPDATE,
        B2BPermissions::COMPANY_TYPE_DEACTIVATE,
        B2BPermissions::COMPANY_TRANSFER_TYPE,
    ];

    /** Every job on the document types (amendment 10). */
    public const array DOCUMENT_JOBS = [
        B2BPermissions::DOCUMENT_TYPE_CREATE,
        B2BPermissions::DOCUMENT_TYPE_UPDATE,
        B2BPermissions::DOCUMENT_TYPE_DEACTIVATE,
    ];

    public function __construct(
        private Authorizer $authorizer,
        private CompanyTypeRepository $companyTypes,
        private DocumentTypeRepository $documentTypes,
        private StoreTypeListsRepository $lists,
        private TypeHolders $holders,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws InvalidCompanyAttribute|Unauthorized
     */
    public function handle(ViewTypeLists $query): TypeListsView
    {
        $jobs = $query->kind === ViewTypeLists::COMPANY ? self::COMPANY_JOBS : self::DOCUMENT_JOBS;

        if (! $this->holdsAnywhere($jobs)) {
            throw new Unauthorized($jobs[0]);
        }

        $store = self::store($query->storeId) ?? throw new Unauthorized($jobs[0]);

        // The one check of the store, as ListCompanies makes its own: any of the list's jobs held
        // there. A second authorize() of one of them would only repeat it (the mutation run of
        // step 7 found the two hiding each other).
        if (! $this->holdsIn($jobs, $store)) {
            throw new Unauthorized($jobs[0]);
        }

        // Only now, and only for someone who may read it, is the store looked up. An off store's
        // lists are read by a Super Admin alone, preparing it (access.md amendment 58(f)).
        $found = $this->platform->store($store);

        if ($found === null || (! $found->isActive && ! $this->authorizer->isUnlimited())) {
            throw new InvalidCompanyAttribute('store', 'a store');
        }

        return new TypeListsView(
            $store->value,
            $query->kind,
            $this->lists->find($store->value)?->copiedNotReviewed() ?? false,
            $query->kind === ViewTypeLists::COMPANY ? $this->companyTypes($store->value) : $this->documentTypes($store->value),
            $this->actions($query->kind, $store),
        );
    }

    /**
     * @return list<StaffTypeView>
     */
    private function companyTypes(string $storeId): array
    {
        $holders = $this->holders->countsFor($storeId);

        return array_map(
            static fn (CompanyType $type): StaffTypeView => new StaffTypeView(
                $type->id(), $type->name()->ar, $type->name()->en, $type->position(), $type->isActive(),
                $type->inactiveDisplay()?->value, null, $holders[$type->id()] ?? 0,
            ),
            $this->companyTypes->all($storeId),
        );
    }

    /**
     * @return list<StaffTypeView>
     */
    private function documentTypes(string $storeId): array
    {
        return array_map(
            static fn (DocumentType $type): StaffTypeView => new StaffTypeView(
                $type->id(), $type->name()->ar, $type->name()->en, $type->position(), $type->isActive(),
                $type->inactiveDisplay()?->value, $type->isRequired(), null,
            ),
            $this->documentTypes->all($storeId),
        );
    }

    private function actions(string $kind, StoreId $store): TypeListActions
    {
        $company = $kind === ViewTypeLists::COMPANY;
        $add = $this->mayIn($company ? B2BPermissions::COMPANY_TYPE_CREATE : B2BPermissions::DOCUMENT_TYPE_CREATE, $store);
        $deactivate = $this->mayIn($company ? B2BPermissions::COMPANY_TYPE_DEACTIVATE : B2BPermissions::DOCUMENT_TYPE_DEACTIVATE, $store);
        $updateCompany = $this->mayIn(B2BPermissions::COMPANY_TYPE_UPDATE, $store);
        $updateDocument = $this->mayIn(B2BPermissions::DOCUMENT_TYPE_UPDATE, $store);

        return new TypeListActions(
            mayReadCompanyTypes: $this->holdsIn(self::COMPANY_JOBS, $store),
            mayReadDocumentTypes: $this->holdsIn(self::DOCUMENT_JOBS, $store),
            mayAdd: $add,
            mayUpdate: $company ? $updateCompany : $updateDocument,
            mayDeactivate: $deactivate,
            mayDeactivateIntoNew: $company && $deactivate && $add,
            mayTransfer: $company && $this->mayIn(B2BPermissions::COMPANY_TRANSFER_TYPE, $store),
            mayMarkReviewed: $updateCompany || $updateDocument,
        );
    }

    /**
     * @param  list<string>  $jobs
     */
    private function holdsAnywhere(array $jobs): bool
    {
        foreach ($jobs as $job) {
            if ($this->authorizer->storesWith($job) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $jobs
     */
    private function holdsIn(array $jobs, StoreId $store): bool
    {
        foreach ($jobs as $job) {
            if ($this->mayIn($job, $store)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the reader holds a job in one store; null from storesWith() is every store.
     */
    private function mayIn(string $permission, StoreId $store): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === null) {
            return true;
        }

        foreach ($stores as $each) {
            if ($each->equals($store)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    private static function store(?string $storeId): ?StoreId
    {
        if ($storeId === null || trim($storeId) === '') {
            return null;
        }

        try {
            return StoreId::fromString(trim($storeId));
        } catch (InvalidArgumentException) {
            throw new InvalidCompanyAttribute('store', 'a store');
        }
    }
}
