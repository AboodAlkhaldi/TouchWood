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

/** Unpublishes a policy (flags-2026-11.md S3.1): back to a draft, its versions kept. The job in every store it serves. */
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
    public function handle(UnpublishPolicy $command): void
    {
        $this->access->holds(self::PERMISSION);
        $checked = $this->rules->served($this->policies->find($command->id));

        foreach ($checked as $storeId) {
            $this->access->authorize(self::PERMISSION, $storeId);
        }

        $this->writer->unpublish($command->id, $command->version, $checked, $this->access->staffId());
    }
}
