<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteRedirect;

/** W11's Delete Line…, asked first on the screen (content.md §1.1 rule 11). */
final readonly class DeleteRedirect
{
    public function __construct(
        public string $line,
    ) {}
}
