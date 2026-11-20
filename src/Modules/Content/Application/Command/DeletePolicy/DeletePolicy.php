<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeletePolicy;

/**
 * **Delete** a draft policy (flags-2026-11.md S3.1, S3.7), at the version the form read: a policy serves several stores,
 * so one changed meanwhile - a store added - is refused rather than deleted unseen (the builder's pick). In the stores
 * picked (the owner's decisions of 2026-11-20); none given is every store it serves.
 */
final readonly class DeletePolicy
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
