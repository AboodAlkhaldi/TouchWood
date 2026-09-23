<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\LinkPages;

use Carbon\CarbonImmutable;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;

/**
 * What a page reached from a link in an email may show before anybody presses anything (frontend.md
 * §3.1, A6–A8).
 *
 * Reading only. Opening one of these links changes nothing — that is the rule the screens exist to
 * keep, because a mail client that fetches every link it receives must not be able to accept an
 * invitation or confirm an address on somebody's behalf.
 *
 * A token that is unknown, spent or expired comes back as null, and the screen sends the person to
 * sign in. The reason is never named: a page that said "expired" for one token and "unknown" for
 * another would tell whoever is guessing which of the two they had.
 */
final readonly class StaffLinkPages
{
    public function __construct(
        private StaffTokenRepository $tokens,
        private StaffUserRepository $staff,
    ) {}

    public function invitation(string $token): ?InvitationPageDto
    {
        $invitation = $this->tokens->invitationByToken(SecretTokens::hash($token));

        if ($invitation === null || $invitation->isExpired(CarbonImmutable::now())) {
            return null;
        }

        $staff = $this->staff->byId($invitation->staffId);

        if ($staff === null) {
            return null;
        }

        $profile = $staff->profile();
        $phone = $staff->phone();

        return new InvitationPageDto(
            name: trim($profile->firstName.' '.$profile->lastName),
            email: $staff->email()->value,
            // The number an admin entered for them, which they may correct before the code is sent
            // (amendment 15). It is shown in full because it is *their* number and they are about
            // to edit it - the masking on the code screen is for a number they cannot change.
            phone: $phone === null ? '' : $phone->value,
            maskedPhone: $phone?->masked(),
        );
    }

    /**
     * The address a staff member asked to move to, for the page that confirms it. The old address
     * is not shown: whoever opens this link has the new one in front of them already.
     */
    public function pendingEmailChange(string $token): ?string
    {
        $change = $this->tokens->emailChangeByToken(SecretTokens::hash($token));

        if ($change === null || $change->expiresAt <= CarbonImmutable::now()) {
            return null;
        }

        return $change->newEmail->value;
    }
}
