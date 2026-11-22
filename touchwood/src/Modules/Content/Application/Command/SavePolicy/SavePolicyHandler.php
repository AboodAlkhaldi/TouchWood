<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SavePolicy;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyRules;
use Modules\Content\Application\Policies\PolicyWriter;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\Policies;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * Saves a policy (flags-2026-11.md S3.1, S3.7) under its own permission, **in every store ticked** (owner,
 * 2026-11-20: a person ticks only stores they hold the job in; the stores a policy keeps and the save leaves alone are
 * not asked), by the rules `PolicyWriter` keeps - a policy shared with other stores splits when its words change for
 * some of them. An id unknown, or serving none of the person's stores, is not found.
 */
final readonly class SavePolicyHandler
{
    public const string PERMISSION = ContentPermissions::POLICY_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Policies $policies,
        private PolicyRules $rules,
        private PolicyWriter $writer,
    ) {}

    /**
     * @return string the id of the policy now serving the stores ticked: a new one when the save split it
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SavePolicy $command): string
    {
        $input = $command->input;
        $this->access->holds(self::PERMISSION);

        if ($input->id !== null) {
            $this->rules->served($this->policies->find($input->id));
        }

        $stores = array_map(fn (string $storeId): StoreDto => $this->access->authorize(self::PERMISSION, $storeId), $this->rules->picked($input->storeIds));

        return $this->writer->save($input, $stores, $this->access->staffId());
    }
}
