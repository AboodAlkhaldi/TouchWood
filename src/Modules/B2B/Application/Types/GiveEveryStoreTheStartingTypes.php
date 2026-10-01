<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Types;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\Model\StoreTypeLists;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\Repository\DocumentTypeRepository;
use Modules\B2B\Domain\Repository\StoreTypeListsRepository;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * Every store whose lists are empty gets the starting ones (b2b.md amendments 5 and 6(a)). It runs
 * when B2B's type tables are migrated, for the stores an installation already has, and for each
 * store opened afterwards through WriteStartingTypes, on Platform's `StoreCreated` — the launch
 * stores included, which the seeder creates after the migrations.
 *
 * It **only ever adds**, one kind at a time: a store that has any company type keeps its company
 * types exactly as they are, and the same for document types, so it may be run again safely.
 * Writing either list into a store that has no "copied, not yet reviewed" row adds one, set; a row
 * that exists is never touched, so a store whose admins reviewed their lists stays reviewed.
 */
final readonly class GiveEveryStoreTheStartingTypes
{
    public function __construct(
        private PlatformApi $platform,
        private CompanyTypeRepository $companyTypes,
        private DocumentTypeRepository $documentTypes,
        private StoreTypeListsRepository $lists,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return int how many stores were given a list
     */
    public function run(): int
    {
        $written = 0;

        // Every store, on and off: a store is set up before it opens (platform.md §1.1).
        foreach ($this->platform->allStores() as $store) {
            if ($this->forStore($store->id)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * @return bool whether it wrote either list
     */
    public function forStore(string $storeId): bool
    {
        $storeId = strtolower($storeId);

        // Self-contained, so a retried attempt reads and writes everything again: the lists, and
        // the flag, commit together or not at all.
        return $this->db->transaction(function () use ($storeId): bool {
            $wrote = false;

            if ($this->companyTypes->all($storeId) === []) {
                foreach (StartingTypes::companyTypes() as $index => $name) {
                    $this->companyTypes->add(CompanyType::add($this->companyTypes->nextId(), $storeId, $name, $index + 1));
                }

                $wrote = true;
            }

            if ($this->documentTypes->all($storeId) === []) {
                foreach (StartingTypes::documentTypes() as $index => $name) {
                    $this->documentTypes->add(DocumentType::add($this->documentTypes->nextId(), $storeId, $name, $index + 1, isRequired: true));
                }

                $wrote = true;
            }

            if ($wrote && $this->lists->find($storeId) === null) {
                $this->lists->add(StoreTypeLists::copied($storeId));
            }

            return $wrote;
        }, 3);
    }
}
