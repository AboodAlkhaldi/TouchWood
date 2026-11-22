<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ApplyRedirectSheet;

/** W11b's Apply Sheet: a checked sheet written (content.md §1.15). */
final readonly class ApplyRedirectSheet
{
    public function __construct(
        public string $uploadId,
    ) {}
}
