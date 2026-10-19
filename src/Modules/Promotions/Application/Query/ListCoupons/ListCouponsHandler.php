<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListCoupons;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\Query\CouponsPage;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MarketingReader;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A store's coupons (promotions.md §3): `promotions.marketing.view` there, or a manage permission;
 * newest first, filtered by state now or the start of the code, a page at a time by keyset.
 */
final readonly class ListCouponsHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private MarketingQueries $queries,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListCoupons $query): CouponsPage
    {
        [$store, $permission] = $this->reader->store($query->storeId);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);
        $perPage = min(max($query->perPage, 1), self::PER_PAGE);

        // Read as a code is typed; a start no code can have finds nothing.
        $typed = trim($query->codeStart ?? '');
        $codeStart = $typed === '' ? null : Inputs::codeStart($typed);

        if ($typed !== '' && $codeStart === null) {
            return new CouponsPage([], null, null);
        }

        $rows = $this->queries->coupons($store, $query->state, $codeStart, $query->beforeAt, $query->beforeId, $perPage + 1, CarbonImmutable::now());
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $last = $more ? end($rows) : false;

        return new CouponsPage($rows, $last === false ? null : $last->createdAt, $last === false ? null : $last->id);
    }
}
