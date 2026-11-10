<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UseFinalAddress;

/** W11's Use Final Address…: a forwarding line sent straight to where it ends (content.md §1.15). */
final readonly class UseFinalAddress
{
    public function __construct(
        public string $line,
    ) {}
}
