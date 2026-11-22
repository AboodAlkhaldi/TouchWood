<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\AddHomeSection;

/** **Add Section** (content.md §1.4, W1): brings back a type removed from the store's home, hidden, at the end. */
final readonly class AddHomeSection
{
    public function __construct(
        public ?string $storeId,
        public string $type,
    ) {}
}
