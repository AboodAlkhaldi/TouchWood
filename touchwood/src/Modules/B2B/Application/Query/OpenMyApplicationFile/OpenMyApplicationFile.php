<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\OpenMyApplicationFile;

/**
 * A link to one of the signed-in company account's own files (b2b.md §1.4, amendment 5).
 */
final readonly class OpenMyApplicationFile
{
    public function __construct(
        public string $mediaId,
    ) {}
}
