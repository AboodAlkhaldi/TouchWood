<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Modules\Access\Domain\Exception\InvitationNotSent;

/**
 * What became of one invitation's email. The email leaves once the transaction commits, so
 * Invitations cannot answer when it is asked to send: it hands this back, and the handler reads it
 * when its transaction has returned (amendment 93).
 */
final class InvitationDelivery
{
    private bool $failed = false;

    public function __construct(public readonly string $staffId) {}

    public function fail(): void
    {
        $this->failed = true;
    }

    /**
     * @throws InvitationNotSent
     */
    public function requireSent(): void
    {
        if ($this->failed) {
            throw new InvitationNotSent($this->staffId);
        }
    }
}
