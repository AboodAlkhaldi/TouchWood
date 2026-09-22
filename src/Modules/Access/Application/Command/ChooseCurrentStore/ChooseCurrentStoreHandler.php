<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChooseCurrentStore;

use InvalidArgumentException;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * Stage 2b, P3. Every staff member may choose which of their own stores the panel shows, under the
 * action everyone has for their own account.
 *
 * It is a preference, never permission: it cannot widen what they see, because every read filters
 * by their own stores anyway. So it is not audited and it raises no session version (owner,
 * 2026-09-22) — a person switches store many times a day, and the audit log is for changes that
 * matter to someone else.
 */
final readonly class ChooseCurrentStoreHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws InvalidAccessAttribute when the store is not one of theirs, or is not a store at all
     */
    public function handle(ChooseCurrentStore $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        // A malformed id is a refusal, not a crash: this arrives from a form. StoreChoice does the
        // same with the stores of a role (review of step 0).
        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidAccessAttribute('store', 'not one of your stores');
        }

        // A store that does not exist is refused like one that is not theirs: the panel never
        // confirms which ids are real.
        if ($this->platform->store($store) === null || ! $this->covers($staffId, $store)) {
            throw new InvalidAccessAttribute('store', 'not one of your stores');
        }

        $this->staff->rememberCurrentStore($staffId, $store->value);
    }

    /**
     * A Super Admin covers every store without a role saying so; anyone else covers the stores of
     * their assignment — the store row chosen for them plus any an exception adds (access.md §1.5).
     */
    private function covers(string $staffId, StoreId $store): bool
    {
        if ($this->rules->author()->isUnlimited()) {
            return true;
        }

        return $this->assignments->byStaff($staffId)?->staffStores()->covers($store) === true;
    }
}
