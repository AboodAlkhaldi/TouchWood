<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Enums\StaffStatus;

/**
 * A staff member, for other modules and for the messages sent to them (Access spec §2.4).
 */
final readonly class StaffDto
{
    /**
     * @param  string  $locale  the communication language, "ar" or "en" (amendment 16)
     */
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public string $locale,
        public StaffStatus $status,
        public bool $isSuperAdmin,
    ) {}
}
