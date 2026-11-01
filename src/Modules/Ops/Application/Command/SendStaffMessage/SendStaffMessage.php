<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SendStaffMessage;

/**
 * A staff member writes to customers of a store (ops.md §1.8): `ONE`, the customer named; `SEGMENT`, the
 * members of the segment named; `STORE`, every customer of the store. The audience is always said outright,
 * and must match what is named: a form that lost its segment never reaches a whole store. Free text in both
 * languages; the customer gets their own. Bell and email, as ticked.
 */
final readonly class SendStaffMessage
{
    public function __construct(
        public string $storeId,
        public string $audience,
        public ?string $customerId,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public bool $byBell,
        public bool $byEmail,
        public ?string $segmentId = null,
    ) {}
}
