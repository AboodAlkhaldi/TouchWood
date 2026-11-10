<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ResetRedirectCount;

/** W11's Reset Count…: a line's visits back to 0 and no last visit (content.md §1.15). */
final readonly class ResetRedirectCount
{
    public function __construct(
        public string $line,
    ) {}
}
