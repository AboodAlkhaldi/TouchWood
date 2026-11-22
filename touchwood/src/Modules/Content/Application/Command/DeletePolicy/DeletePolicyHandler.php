<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeletePolicy;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyRules;
use Modules\Content\Application\Policies\PolicyWriter;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\Policies;
use Shared\Application\Unauthorized;

/**
 * Deletes a policy never published (flags-2026-11.md S3.1; owner, 2026-11-20): once published it is only unpublished,
 * so every version stays (`PolicyNotDeletable`); its addresses are freed. In the stores picked (the owner's decisions of
 * 2026-11-20): all of them deletes it, some take those stores off it. The job in each store picked.
 */
final readonly class DeletePolicyHandler
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
    public function handle(DeletePolicy $command): void
    {
        $this->access->holds(self::PERMISSION);
        $served = $this->rules->served($this->policies->find($command->id));
        $picked = $command->storeIds === null ? $served : $this->rules->picked($command->storeIds);

        foreach ($picked as $storeId) {
            $this->access->authorize(self::PERMISSION, $storeId);
        }

        $this->writer->delete($command->id, $command->version, $picked, $this->access->staffId());
    }
}
