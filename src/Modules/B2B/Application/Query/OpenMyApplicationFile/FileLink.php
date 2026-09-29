<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\OpenMyApplicationFile;

use DateTimeImmutable;

/**
 * A signed link to a private file, and when it stops working (30 minutes, platform.md §1.4).
 */
final readonly class FileLink
{
    public function __construct(
        public string $url,
        public DateTimeImmutable $expiresAt,
    ) {}
}
