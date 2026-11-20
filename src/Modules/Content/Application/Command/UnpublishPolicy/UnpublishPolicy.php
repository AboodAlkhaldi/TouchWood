<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UnpublishPolicy;

/** **Unpublish** a policy (flags-2026-11.md S3.1, S3.7), at the version the form read. */
final readonly class UnpublishPolicy
{
    public function __construct(
        public string $id,
        public int $version,
    ) {}
}
