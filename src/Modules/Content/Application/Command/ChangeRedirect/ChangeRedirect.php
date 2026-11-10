<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ChangeRedirect;

/** W11's Change…: a line found by its old address as kept, given both boxes again (content.md §1.15). */
final readonly class ChangeRedirect
{
    public function __construct(
        public string $line,
        public ?string $oldPath,
        public ?string $newPath,
    ) {}
}
