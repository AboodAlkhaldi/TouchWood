<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

/**
 * One staff member a staff alert goes to (amendment 80; ops.md §1.4), with their switches for its
 * topic. A topic with no row of theirs reads as its default: in the panel on, email off (amendment 19).
 */
final readonly class StaffAlertRecipientDto
{
    /**
     * @param  string  $locale  the communication language, "ar" or "en" (amendment 16)
     * @param  string|null  $phone  E.164, for the critical SMS; null only after a console reset
     * @param  bool  $criticalSms  their critical-SMS switch (amendment 81), on unless they turned it off
     */
    public function __construct(
        public string $staffId,
        public string $locale,
        public string $emailAddress,
        public ?string $phone,
        public bool $byPanel,
        public bool $byEmail,
        public bool $criticalSms,
    ) {}
}
