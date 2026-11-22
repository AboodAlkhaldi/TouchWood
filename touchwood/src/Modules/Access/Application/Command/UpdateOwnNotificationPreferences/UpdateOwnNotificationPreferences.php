<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateOwnNotificationPreferences;

/**
 * A staff member's own notification toggles (handoff §7.6). Topics not given keep their toggles.
 */
final readonly class UpdateOwnNotificationPreferences
{
    /**
     * @param  array<string, array{email: bool, panel: bool}>  $preferences  topic (StaffNotificationTopic value) => toggles
     */
    public function __construct(
        public array $preferences,
    ) {}
}
