<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

/**
 * **A sale or a category discount an entry names** (sync.md §4.1.3, Q37): whether it still exists and,
 * while it does, its short description in the reader's language (Pricing's `salesNamed`,
 * `discountsNamed`) - "Offer removed", with no link, once it is gone.
 */
final readonly class OfferRefView
{
    public function __construct(
        public string $id,
        public bool $exists,
        public ?string $description,
    ) {}
}
