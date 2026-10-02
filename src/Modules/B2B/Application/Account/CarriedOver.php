<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Account;

use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Applying in a second store** (b2b.md amendments 19(b), 20(a) and (b)): the form starts with the
 * **name and the company type** of the account's company in another store, both editable; the
 * address, the CR number, the tax number and the papers are entered fresh — they belong to that
 * country.
 *
 * - **Which company**: an approved one first, otherwise the newest (20(b)) — of the stores that are
 *   **on** only: an off store is as if it were never there (platform.md §1.6), so nothing is
 *   carried from it and the page never names it (review of amendment 18).
 * - **The type**: company types are each store's own list, so the other store's type is carried
 *   over only as its **counterpart** here — an active type of this store with the same names,
 *   ignoring case and the spaces at either end. A type matching both its Arabic and its English
 *   name wins; failing that, the one type matching either name; if two match by one name each, the
 *   choice would be a guess, and the type is left empty (20(a), as amended after the review). A
 *   company still "Other" brings its own words.
 */
final readonly class CarriedOver
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyTypeRepository $companyTypes,
        private PlatformApi $platform,
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
        $elsewhere = array_values(array_filter(
            $this->companies->allForCustomer($customerId),
            fn (Company $company): bool => $this->platform->store(StoreId::fromString($company->homeStoreId()))?->isActive === true,
        ));

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

        $both = [];
        $either = [];

        foreach ($this->companyTypes->all($storeId) as $candidate) {
            if (! $candidate->isActive()) {
                continue;
            }

            $ar = self::same($candidate->name()->ar, $type->name()->ar);
            $en = self::same($candidate->name()->en, $type->name()->en);

            if ($ar && $en) {
                $both[] = $candidate;
            } elseif ($ar || $en) {
                $either[] = $candidate;
            }
        }

        $counterpart = match (true) {
            $both !== [] => $both[0],
            count($either) === 1 => $either[0],
            default => null,
        };

        return $counterpart === null ? null : CompanyTypeChoice::listed($counterpart->id());
    }

    private static function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
