<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Query\ShopLine\CompanyStanding;
use Modules\B2B\Application\Query\ShopLine\CompanyStandings;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * One round trip, on the account's unique company row and its open-application index (b2b.md
 * §5.2), because it is asked on every shop page a company account opens.
 */
final readonly class DatabaseCompanyStandings implements CompanyStandings
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function of(string $customerId): CompanyStanding
    {
        if (! Ulids::valid($customerId)) {
            return new CompanyStanding(null, null, false);
        }

        $id = strtolower($customerId);
        $row = $this->db->selectOne(<<<'SQL'
            SELECT
                (SELECT status FROM b2b.companies WHERE customer_id = ?) AS status,
                (SELECT status_reason FROM b2b.companies WHERE customer_id = ?) AS status_reason,
                EXISTS (SELECT 1 FROM b2b.applications WHERE customer_id = ? AND state = 'DRAFT') AS draft_open
            SQL, [$id, $id, $id]);

        return new CompanyStanding(
            $row->status === null ? null : CompanyStatus::from((string) $row->status),
            $row->status_reason === null ? null : (string) $row->status_reason,
            (bool) $row->draft_open,
        );
    }
}
