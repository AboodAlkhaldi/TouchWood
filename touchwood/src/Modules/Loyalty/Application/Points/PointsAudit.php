<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * What Loyalty writes to Platform's audit log (loyalty.md §3): a change by hand, on the customer's
 * account in its store, with the points and the reason. Platform adds who and when. The nightly
 * expiry is not audited: the ledger is its record.
 */
final class PointsAudit
{
    public const string SUBJECT = 'loyalty.account';

    public static function byHand(string $action, PointsAccount $account, int $points, string $reason): AuditEntryDto
    {
        return new AuditEntryDto($action, self::SUBJECT, $account->id, $account->store->value, AuditChanges::none()
            ->changed('points', null, $points)
            ->changed('reason', null, $reason));
    }
}
