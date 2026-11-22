<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * Every invitation link, from wherever it is sent. A new link replaces the earlier one and its
 * phone code. A Super Admin's works 24 hours, anyone else's the staff invitation hours (amendment
 * 30). The email leaves once the transaction commits, never through the queue: a queued job would
 * keep the link in the jobs table. A provider's failure goes to the log and leaves what was committed
 * alone; the caller must then read the answer, and say it was not sent (amendment 93).
 */
final readonly class Invitations
{
    public function __construct(
        private StaffTokenRepository $tokens,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @param  string|null  $sentBy  the staff member sending it; null for the console
     * @return InvitationDelivery read it once the transaction has returned, not inside it
     */
    public function send(StaffUser $staff, ?string $sentBy): InvitationDelivery
    {
        $hours = $staff->isSuperAdmin() ? $this->settings->superAdminInvitationHours() : $this->settings->invitationHours();
        $invitation = SecretTokens::issue();

        $this->tokens->putInvitation($staff->id(), $invitation['hash'], CarbonImmutable::now()->addHours($hours), $sentBy);
        $this->tokens->deletePhoneCode($staff->id());

        $delivery = new InvitationDelivery($staff->id());

        // Caught here, not left to leave through the commit: an exception there would stop the other
        // callbacks of this commit too.
        $this->db->afterCommit(fn () => rescue(
            fn () => $this->messages->staffInvitation(StaffMapper::toDto($staff), $this->links->invitation($invitation['token'])),
            function () use ($delivery, $staff): void {
                $delivery->fail();
                // Beside any entry the transaction wrote: "invitation resent" says a link was issued, this
                // says the email did not go. Never a reason to stop what follows.
                rescue(fn () => $this->platform->recordAudit(StaffAudit::event('access.staff_user.invitation_not_sent', $staff)));
            },
        ));

        return $delivery;
    }
}
