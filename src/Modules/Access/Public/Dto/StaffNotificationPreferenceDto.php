<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Enums\StaffNotificationTopic;

final readonly class StaffNotificationPreferenceDto
{
    public function __construct(
        public StaffNotificationTopic $topic,
        public bool $email,
        public bool $panel,
    ) {}
}
