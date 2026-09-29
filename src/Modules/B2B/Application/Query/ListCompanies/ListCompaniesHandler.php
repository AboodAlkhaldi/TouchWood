<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ListCompanies;

use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Public\Enums\CompanyStatus;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **`ListCompanies`** (b2b.md §3.2, amendment 10): the companies of the reader's own stores — every
 * store for someone who holds the job everywhere, a Super Admin included. Filtered by status and by
 * store, searched by name, CR number or tax number; waiting companies first, the oldest sent first.
 * A store the reader does not cover is refused as not allowed (10(j)). Only companies: an account
 * with nothing but a draft has sent nothing to review (§1.1).
 */
final readonly class ListCompaniesHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_VIEW;

    private const int PER_PAGE_MAX = 100;

    public function __construct(
        private Authorizer $authorizer,
        private CompanyReader $companies,
    ) {}

    /**
     * @throws InvalidCompanyAttribute|Unauthorized
     */
    public function handle(ListCompanies $query): CompanyPage
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);

        if ($stores === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        // null is what storesWith() answers for every store, now and for one opened later.
        $storeIds = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);

        if ($query->storeId !== null && trim($query->storeId) !== '') {
            $wanted = self::store($query->storeId);

            if ($storeIds !== null && ! in_array($wanted, $storeIds, true)) {
                throw new Unauthorized(self::PERMISSION);
            }

            $storeIds = [$wanted];
        }

        $status = $query->status === null || trim($query->status) === ''
            ? null
            : (CompanyStatus::tryFrom(strtoupper(trim($query->status))) ?? throw new InvalidCompanyAttribute('status', 'one of the four statuses'))->value;
        $page = max($query->page, 1);
        $perPage = min(max($query->perPage, 1), self::PER_PAGE_MAX);

        [$rows, $total] = $this->companies->companies($storeIds, $query->search, $status, $page, $perPage);

        return new CompanyPage($rows, $total, $page, $perPage);
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    private static function store(string $storeId): string
    {
        try {
            return StoreId::fromString(trim($storeId))->value;
        } catch (\InvalidArgumentException) {
            throw new InvalidCompanyAttribute('store', 'a store');
        }
    }
}
