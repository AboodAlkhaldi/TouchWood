<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Public\Enums\StaffNotificationTopic;

final readonly class DatabaseNotificationPreferenceRepository implements NotificationPreferenceRepository
{
    private const string TABLE = 'access.staff_notification_preferences';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function createDefaults(string $staffId): void
    {
        $this->db->table(self::TABLE)->insertOrIgnore(array_map(
            fn (StaffNotificationTopic $topic): array => ['staff_user_id' => $staffId, 'topic' => $topic->value, 'email' => false, 'panel' => true],
            StaffNotificationTopic::cases(),
        ));
    }

    public function of(string $staffId): array
    {
        $stored = [];

        foreach ($this->db->table(self::TABLE)->where('staff_user_id', $staffId)->get() as $row) {
            $stored[(string) $row->topic] = ['email' => (bool) $row->email, 'panel' => (bool) $row->panel];
        }

        $preferences = [];

        // Every topic, in a fixed order: one added later reads as its default until chosen.
        foreach (StaffNotificationTopic::cases() as $topic) {
            $preferences[$topic->value] = $stored[$topic->value] ?? ['email' => false, 'panel' => true];
        }

        return $preferences;
    }

    public function set(string $staffId, StaffNotificationTopic $topic, bool $email, bool $panel): void
    {
        $this->db->table(self::TABLE)->upsert(
            [['staff_user_id' => $staffId, 'topic' => $topic->value, 'email' => $email, 'panel' => $panel]],
            ['staff_user_id', 'topic'],
            ['email', 'panel'],
        );
    }
}
