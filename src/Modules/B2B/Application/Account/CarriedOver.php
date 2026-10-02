<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Account;

use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * **Applying in a second store** (b2b.md amendments 19(b), 20(a) and (b)): the form starts with the
 * **name and the company type** of the account's company in another store, both editable; the
 * address, the CR number, the tax number and the papers are entered fresh — they belong to that
 * country.
 *
 * - **Which company**: an approved one first, otherwise the newest (20(b)).
 * - **The type**: company types are each store's own list, so the other store's type is carried
 *   over only as its **counterpart** here — an active type of this store with the same Arabic or
 *   English name, ignoring case and the spaces at either end — and left empty when there is none;
 *   a company still "Other" brings its own words (20(a)).
 */
final readonly class CarriedOver
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyTypeRepository $companyTypes,
    ) {}

    /**
     * The account's company the form starts from, or null when it has none.
     *
     * **Asked only while the account has no company in the store being applied in** — by the start
     * of a first draft there and by the company page offering "Apply in this store" — so every
     * company the account holds is in another store. (A filter for "another store" was written and
     * the mutation run showed it could never change an answer, so it is not here.)
     */
    public function source(string $customerId): ?Company
    {
        $elsewhere = $this->companies->allForCustomer($customerId);

        foreach ($elsewhere as $company) {
            if ($company->status() === CompanyStatus::Approved) {
                return $company;
            }
        }

        // Newest first, as the repository answers.
        return $elsewhere[0] ?? null;
    }

    public function name(Company $source): CompanyName
    {
        return $source->details()->name;
    }

    /**
     * The type the form starts with in this store: the counterpart of the source's, "Other" as it
     * was, or none.
     */
    public function type(Company $source, string $storeId): ?CompanyTypeChoice
    {
        $theirs = $source->details()->type;

        if ($theirs->typeId === null) {
            return $theirs;
        }

        $type = $this->companyTypes->find($theirs->typeId);

        if ($type === null) {
            return null;
        }

        foreach ($this->companyTypes->all($storeId) as $candidate) {
            if ($candidate->isActive() && self::sameName($candidate, $type)) {
                return CompanyTypeChoice::listed($candidate->id());
            }
        }

        return null;
    }

    private static function sameName(CompanyType $here, CompanyType $there): bool
    {
        $same = static fn (string $a, string $b): bool => mb_strtolower(trim($a)) === mb_strtolower(trim($b));

        return $same($here->name()->ar, $there->name()->ar) || $same($here->name()->en, $there->name()->en);
    }
}
