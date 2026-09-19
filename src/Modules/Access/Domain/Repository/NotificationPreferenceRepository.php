<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Public\Enums\StaffNotificationTopic;

interface NotificationPreferenceRepository
{
    /**
     * A new staff member's: every in-panel toggle on, every email toggle off (owner's decision,
     * 2026-09-19).
     */
    public function createDefaults(string $staffId): void;

    /**
     * @return array<string, array{email: bool, panel: bool}> topic value => toggles, every topic
     */
    public function of(string $staffId): array;

    public function set(string $staffId, StaffNotificationTopic $topic, bool $email, bool $panel): void;
}
