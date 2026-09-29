<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\MarkTypeListsReviewed;

use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Types\StaffTypeAction;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **`MarkTypeListsReviewed`** (b2b.md §1.3, §3.2, amendment 10(d)): clears the store's "copied from
 * the starting lists" notice when nothing needs changing — any change to either list clears it anyway.
 * Either list's `update` job in that store will do; audited only when the notice was showing.
 */
final readonly class MarkTypeListsReviewedHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_TYPE_UPDATE;

    /** The other job that may press "Reviewed". */
    public const string OR_PERMISSION = B2BPermissions::DOCUMENT_TYPE_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffTypeAction $action,
    ) {}

    /**
     * @throws InvalidCompanyAttribute|Unauthorized
     */
    public function handle(MarkTypeListsReviewed $command): void
    {
        $storeId = null;
        $refusal = null;

        foreach ([self::PERMISSION, self::OR_PERMISSION] as $permission) {
            try {
                /** @var array{PermissionScope, string} $store */
                $store = $this->action->store($permission, $command->storeId);
                $this->authorizer->authorize($permission, $store[0]);
                $storeId = $store[1];

                break;
            } catch (Unauthorized $refused) {
                $refusal ??= $refused;
            }
        }

        if ($storeId === null) {
            throw $refusal ?? new Unauthorized(self::PERMISSION);
        }

        $this->action->markReviewed($storeId);
    }
}
