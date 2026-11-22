<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\PruneSearchLog;

use Carbon\CarbonImmutable;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Search\SearchLog;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The nightly removal** (catalog.md §1.11): search log entries older than twelve months go. It is
 * system maintenance — it changes nothing a person did — so it audits nothing.
 */
final readonly class PruneSearchLogHandler
{
    /** Reserved: the system's, or a Super Admin's. */
    public const string PERMISSION = CatalogPermissions::SEARCH_LOG_PRUNE;

    /** How long an entry is kept (owner, 2026-10-02). */
    public const int MONTHS = 12;

    public function __construct(
        private Authorizer $authorizer,
        private SearchLog $log,
    ) {}

    /**
     * @return int how many entries went
     *
     * @throws Unauthorized
     */
    public function handle(PruneSearchLog $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return $this->log->prune(CarbonImmutable::now()->subMonthsNoOverflow(self::MONTHS));
    }
}
