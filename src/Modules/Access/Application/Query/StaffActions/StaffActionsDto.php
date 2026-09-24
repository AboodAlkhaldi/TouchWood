<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\StaffActions;

/**
 * What the person acting now may do to one staff member (frontend.md §3.3, C2).
 *
 * Asked once, so a screen can offer a button or leave it out. Offering is never allowing: every one
 * of these is checked again by the handler behind it, with the stores in hand, so the same rule
 * holds whether the action arrives from a screen, the console or a queued job.
 */
final readonly class StaffActionsDto
{
    public function __construct(
        public bool $mayEditProfile,
        public bool $mayChangeEmail,
        public bool $mayChangeRole,
        public bool $mayDisable,
        public bool $mayEnable,
        public bool $mayResendInvitation,
        public bool $mayCancelInvitation,
        public bool $mayRefresh,
    ) {}

    /**
     * Nothing at all: a Super Admin, or the reader themselves.
     */
    public static function none(): self
    {
        return new self(false, false, false, false, false, false, false, false);
    }
}
