<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListSearchesWithNoResults;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The searches that found nothing** (catalog.md §1.11, §3, §4.4 S7, P11): reading them is
 * `catalog.search_word.manage` **with All stores**, as adding the pairs they lead to is (§3). The
 * last twelve months — what the log keeps — grouped by the words, the store and the language, the
 * most searched first, a page at a time.
 */
final readonly class ListSearchesWithNoResultsHandler
{
    public const string PERMISSION = CatalogPermissions::SEARCH_WORD_MANAGE;

    public const int PER_PAGE_MAX = 100;

    /** A page past this is nobody's, and a larger one overflows the offset. */
    private const int PAGE_MAX = 10_000;

    public function __construct(
        private Authorizer $authorizer,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListSearchesWithNoResults $query): NoResultSearchList
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::allStores());
        $page = min(max($query->page, 1), self::PAGE_MAX);
        $perPage = min(max($query->perPage, 1), self::PER_PAGE_MAX);
        $store = self::store($query->storeId);

        [$searches, $more] = $this->reads->searchesWithNoResults(
            $store?->value,
            CarbonImmutable::now()->subMonthsNoOverflow(12)->toIso8601String(),
            $page,
            $perPage,
        );

        return new NoResultSearchList($searches, $page, $more);
    }

    /**
     * A malformed store id is refused as a store the reader may not choose would be (the page's
     * filter offers only real ones), never read as "every store".
     *
     * @throws Unauthorized
     */
    private static function store(?string $storeId): ?StoreId
    {
        if ($storeId === null || trim($storeId) === '') {
            return null;
        }

        try {
            return StoreId::fromString(trim($storeId));
        } catch (InvalidArgumentException) {
            throw new Unauthorized(self::PERMISSION);
        }
    }
}
