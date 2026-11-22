<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\LinkPages;

/**
 * What the invitation screen shows before anyone presses anything (frontend.md §3.1, A6).
 */
final readonly class InvitationPageDto
{
    /**
     * @param  string  $phone  the number an admin entered, which the invitee may correct; an empty
     *                         string when none was entered
     * @param  string|null  $maskedPhone  the same number as the code screen will name it
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public ?string $maskedPhone,
    ) {}
}
