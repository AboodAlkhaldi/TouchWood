<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteSmartBar;

/** Deleting a smart bar (content.md §1.9, W2): it has no draft, so it goes whatever its dates. */
final readonly class DeleteSmartBar
{
    public function __construct(
        public string $smartBarId,
    ) {}
}
