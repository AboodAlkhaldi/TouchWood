<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SendStaffMessage;

/**
 * A staff member writes to customers of a store (ops.md §1.8): to the one customer named, or - with
 * no customer - to every customer of the store. Free text in both languages; the customer gets their
 * own. Bell and email, as ticked.
 */
final readonly class SendStaffMessage
{
    public function __construct(
        public string $storeId,
        public ?string $customerId,
        public string $titleAr,
        public string $titleEn,
        public string $bodyAr,
        public string $bodyEn,
        public bool $byBell,
        public bool $byEmail,
    ) {}
}
