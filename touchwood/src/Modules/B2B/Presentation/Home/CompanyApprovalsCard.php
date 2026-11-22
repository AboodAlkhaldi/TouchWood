<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Home;

use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Query\ListCompanies\CompanyReader;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * B2B's card on the admin home, Company Approvals (b2b.md amendment 27; the owner, 2026-10-05):
 * how many companies wait for a decision and who - the oldest waiting first - and the companies by
 * status. Counted by the reader the companies list uses, and linking to the list for the same
 * stores, so the two never disagree.
 *
 * The home offers it only to a reader holding b2b.company.view in the scope; it asks again itself,
 * as the list's handler does, because it reads across stores (handoff §19).
 */
final readonly class CompanyApprovalsCard implements HomeCard
{
    /** How many of the waiting companies are named. */
    public const int NAMED = 5;

    public function __construct(
        private CompanyReader $companies,
        private PlatformApi $platform,
        private Authorizer $authorizer,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $scope->stores() === null ? null : array_map(static fn (StoreId $store): string => $store->value, $scope->stores());

        if (! $this->mayRead($stores)) {
            return null;
        }

        $counts = $this->companies->statusCounts($stores);
        [$waiting] = $this->companies->companies($stores, null, CompanyStatus::Pending->value, 1, self::NAMED);
        $pending = $counts[CompanyStatus::Pending->value] ?? 0;
        // This Store: the list opened on the same store, so its count is the card's.
        $store = $stores !== null && count($stores) === 1 ? $stores[0] : null;
        $locale = app()->getLocale();
        $names = [];

        return new HomeCardData(
            [
                new HomeFigure('waiting', $pending, href: self::list(CompanyStatus::Pending, $store), tone: $pending > 0 ? 'amber' : null),
                new HomeFigure('approved', $counts[CompanyStatus::Approved->value] ?? 0, href: self::list(CompanyStatus::Approved, $store)),
                new HomeFigure('not_approved', $counts[CompanyStatus::Rejected->value] ?? 0, href: self::list(CompanyStatus::Rejected, $store)),
                new HomeFigure('suspended', $counts[CompanyStatus::Suspended->value] ?? 0, href: self::list(CompanyStatus::Suspended, $store)),
            ],
            array_map(function ($company) use (&$names, $locale): HomeRow {
                // Each store looked up once, on or off: a company is named with its store as it is.
                $names[$company->homeStoreId] ??= $this->platform->store(StoreId::fromString($company->homeStoreId))?->name->in($locale);

                return new HomeRow(
                    $company->name,
                    $names[$company->homeStoreId],
                    $company->waitingSince,
                    route('b2b.admin.companies.show', ['company' => $company->id], absolute: false),
                );
            }, $waiting),
            $waiting === [] ? null : 'waiting_list',
            self::list(CompanyStatus::Pending, $store),
        );
    }

    /**
     * @param  list<string>|null  $stores  null for every store
     */
    private function mayRead(?array $stores): bool
    {
        $held = $this->authorizer->storesWith(B2BPermissions::COMPANY_VIEW);

        if ($held === null) {
            return true;
        }

        if ($stores === null) {
            return false;
        }

        return array_diff($stores, array_map(static fn (StoreId $store): string => $store->value, $held)) === [];
    }

    private static function list(CompanyStatus $status, ?string $store): string
    {
        return route('b2b.admin.companies', array_filter(['status' => $status->value, 'store' => $store]), absolute: false);
    }
}
