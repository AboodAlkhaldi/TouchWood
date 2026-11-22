<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a points history (loyalty.md §4.1 L2, L7): `kind` is the entry's (EARNED, REDEEMED,
 * REDEMPTION_RETURNED, EARNING_REVERSED, ADDED, DEDUCTED, EXPIRED); points signed, + in and - out.
 * The staff member's name and the reason only ever reach a staff screen: the customer's read strips
 * them (§3).
 */
#[TypeScript]
final class HistoryRowData extends Data
{
    public function __construct(
        public int $id,
        public string $kind,
        public int $points,
        public string $occurredAt,
        public ?string $orderNumber,
        public ?string $staffName,
        public ?string $reason,
    ) {}
}
