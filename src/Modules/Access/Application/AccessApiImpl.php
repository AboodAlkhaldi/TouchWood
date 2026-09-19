<?php

declare(strict_types=1);

namespace Modules\Access\Application;

use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Dto\StaffNotificationPreferenceDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;

final readonly class AccessApiImpl implements AccessApi
{
    public function __construct(
        private StaffUserRepository $staff,
        private NotificationPreferenceRepository $preferences,
    ) {}

    public function staff(string $staffId): ?StaffDto
    {
        $staff = $this->staff->find($staffId);

        return $staff === null ? null : StaffMapper::toDto($staff);
    }

    public function staffNotificationPreferences(string $staffId): array
    {
        if ($this->staff->find($staffId) === null) {
            return [];
        }

        $preferences = [];

        foreach ($this->preferences->of(strtolower($staffId)) as $topic => $toggles) {
            $preferences[] = new StaffNotificationPreferenceDto(StaffNotificationTopic::from($topic), $toggles['email'], $toggles['panel']);
        }

        return $preferences;
    }
}
