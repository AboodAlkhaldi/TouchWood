<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\PublishPolicy;

/** **Publish** a policy (flags-2026-11.md S3.1, S3.7), at the version the form read. */
final readonly class PublishPolicy
{
    public function __construct(
        public string $id,
        public int $version,
        /** "I read the translated English." ticked (content.md §1.3, P14). */
        public bool $read = false,
    ) {}
}
