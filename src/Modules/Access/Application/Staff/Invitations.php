<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Public\Contracts\SecurityMessages;

/**
 * Every invitation link, from wherever it is sent. A new link replaces the earlier one and its
 * phone code. A Super Admin's works 24 hours, anyone else's the staff invitation hours (amendment
 * 30). The email leaves once the transaction commits, never through the queue: a queued job would
 * keep the link in the jobs table.
 */
final readonly class Invitations
{
    public function __construct(
        private StaffTokenRepository $tokens,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private Connection $db,
    ) {}

    /**
     * @param  string|null  $sentBy  the staff member sending it; null for the console
     */
    public function send(StaffUser $staff, ?string $sentBy): void
    {
        $hours = $staff->isSuperAdmin() ? $this->settings->superAdminInvitationHours() : $this->settings->invitationHours();
        $invitation = SecretTokens::issue();

        $this->tokens->putInvitation($staff->id(), $invitation['hash'], CarbonImmutable::now()->addHours($hours), $sentBy);
        $this->tokens->deletePhoneCode($staff->id());

        $this->db->afterCommit(fn () => $this->messages->staffInvitation(StaffMapper::toDto($staff), $this->links->invitation($invitation['token'])));
    }
}
