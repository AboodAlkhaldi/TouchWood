<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UnpublishPolicy;

/**
 * **Unpublish** a policy (flags-2026-11.md S3.1, S3.7), at the version the form read, in the stores picked (the owner's
 * decisions of 2026-11-20: per store, as a save is); none given is every store it serves.
 */
final readonly class UnpublishPolicy
{
    /**
     * @param  list<string>|null  $storeIds
     */
    public function __construct(
        public string $id,
        public int $version,
        public ?array $storeIds = null,
    ) {}
}
