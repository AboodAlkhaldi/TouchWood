<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Media;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUseDto;
use Shared\Application\Authorizer;

/**
 * Staff avatars are public media (spec §1.4): deleting one leaves its staff member with no avatar.
 * Detaching changes their profile, so it needs the right to edit that staff member — or it is
 * their own avatar (Platform rule, 2026-09-18).
 */
final readonly class StaffAvatarUsage implements MediaUsage
{
    public function __construct(
        private ConnectionInterface $db,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantRules $rules,
        private GrantsReader $grants,
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    public function usesOf(string $mediaId): array
    {
        $uses = [];

        foreach ($this->db->table('access.staff_users')->where('avatar_media_id', $mediaId)->orderBy('id')->pluck('id') as $staffId) {
            $uses[] = new MediaUseDto('access.staff_user', (string) $staffId, false);
        }

        return $uses;
    }

    public function detach(string $mediaId): void
    {
        $author = $this->rules->author();

        foreach ($this->usesOf($mediaId) as $use) {
            $staff = $this->staff->byId($use->subjectId);

            if ($staff === null) {
                continue;
            }

            if (! $author->isUnlimited() && $author->staffId !== $staff->id()) {
                foreach ($this->rules->scopesFor($this->assignments->byStaff($staff->id())?->staffStores()) as $scope) {
                    $this->authorizer->authorize(AccessPermissions::STAFF_UPDATE, $scope);
                }

                $this->rules->requireManageable($author, $staff, $this->grants->forStaff($staff->id()));
            }

            $before = clone $staff;
            $staff->changeAvatar(null);
            $this->staff->update($staff);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.avatar_detached', $before, $staff, $staff->pullChanges()));
        }
    }
}
