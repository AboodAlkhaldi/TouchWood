<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UnpublishPolicy;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyRules;
use Modules\Content\Application\Policies\PolicyWriter;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\Policies;
use Shared\Application\Unauthorized;

/**
 * Unpublishes a policy (flags-2026-11.md S3.1) in the stores picked (the owner's decisions of 2026-11-20): all of them,
 * back to a draft, its versions kept; some, those stores leave it into a switched-off copy of their own
 * (`PolicyWriter::unpublish`). The job in each store picked; one it does not serve is refused.
 */
final readonly class UnpublishPolicyHandler
{
    public const string PERMISSION = ContentPermissions::POLICY_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Policies $policies,
        private PolicyRules $rules,
        private PolicyWriter $writer,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(UnpublishPolicy $command): ?string
    {
        $this->access->holds(self::PERMISSION);
        $served = $this->rules->served($this->policies->find($command->id));
        $picked = $command->storeIds === null ? $served : $this->rules->picked($command->storeIds);

        foreach ($picked as $storeId) {
            $this->access->authorize(self::PERMISSION, $storeId);
        }

        return $this->writer->unpublish($command->id, $command->version, $picked, $this->access->staffId());
    }
}
