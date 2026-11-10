<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\AddRedirect;

/** W11's Add Line: an old address and the shop page it goes to (content.md §1.15). */
final readonly class AddRedirect
{
    public function __construct(
        public ?string $oldPath,
        public ?string $newPath,
    ) {}
}
