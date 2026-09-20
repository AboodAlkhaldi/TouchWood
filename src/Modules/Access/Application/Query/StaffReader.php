<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query;

/**
 * The rows the staff screens read about staff (spec §3.2, §3.3). Reads only. Which of them a
 * reader may see, and how much of each, is decided in the handlers (amendment 43).
 */
interface StaffReader
{
    /**
     * Every staff member who is not a Super Admin, newest first. A Super Admin is never listed
     * here: only a Super Admin may see one, and they read `superAdmins()`.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function staff(?string $search, ?string $status, int $page, int $perPage): array;

    /**
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function superAdmins(?string $search, ?string $status, int $page, int $perPage): array;

    /**
     * @return array<string, mixed>|null
     */
    public function member(string $staffId): ?array;
}
