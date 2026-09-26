<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Session\StaffSessionDirectory;

/**
 * The panel's session rows, read and deleted straight from the table Laravel keeps them in.
 *
 * `user_id` is filled by {@see StaffSessionHandler}; before that driver existed every row carried
 * an empty one, which is why this could not have been written then.
 */
final readonly class DatabaseStaffSessionDirectory implements StaffSessionDirectory
{
    private const string TABLE = 'access.admin_sessions';

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return list<array{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     */
    public function forStaff(string $staffId): array
    {
        $rows = $this->db->table(self::TABLE)
            ->where('user_id', $staffId)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return array_values(array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'ip_address' => $row->ip_address === null ? null : (string) $row->ip_address,
            'user_agent' => $row->user_agent === null ? null : (string) $row->user_agent,
            'last_activity' => (int) $row->last_activity,
        ], $rows->all()));
    }

    public function end(string $sessionId, string $staffId): void
    {
        // Both, always: a session id from a request may name somebody else's row.
        $this->db->table(self::TABLE)->where('id', $sessionId)->where('user_id', $staffId)->delete();
    }

    public function endAll(string $staffId): void
    {
        $this->db->table(self::TABLE)->where('user_id', $staffId)->delete();
    }
}
