<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Repository\WaitingJobs;
use stdClass;

/** `WaitingJobs` in SQL (`content.waiting_jobs`): one row a job, written by the migration. */
final readonly class DatabaseWaitingJobs implements WaitingJobs
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function ask(string $job, DateTimeImmutable $at): bool
    {
        $row = $this->db->selectOne('SELECT queued_at FROM content.waiting_jobs WHERE job = ? FOR UPDATE', [$job]);

        if (! $row instanceof stdClass || $row->queued_at !== null) {
            return false;
        }

        $this->db->table('content.waiting_jobs')->where('job', $job)->update(['queued_at' => Rows::at($at)]);

        return true;
    }

    public function want(string $job, DateTimeImmutable $at, bool $now): void
    {
        $this->db->update(
            'UPDATE content.waiting_jobs SET wanted_at = COALESCE(wanted_at, ?), wanted_now = wanted_now OR '.($now ? 'true' : 'false').' WHERE job = ?',
            [Rows::at($at), $job],
        );
    }

    public function takeWanted(string $job, DateTimeImmutable $at, string $today): bool
    {
        $row = $this->db->selectOne('SELECT queued_at, wanted_at, wanted_now, extra_on FROM content.waiting_jobs WHERE job = ? FOR UPDATE', [$job]);

        if (! $row instanceof stdClass || $row->queued_at !== null || $row->wanted_at === null) {
            return false;
        }

        $now = (bool) $row->wanted_now;

        if (! $now && (string) $row->extra_on === $today) {
            return false;
        }

        $this->db->table('content.waiting_jobs')->where('job', $job)->update([
            'queued_at' => Rows::at($at),
            'wanted_at' => null,
            'wanted_now' => false,
            ...($now ? [] : ['extra_on' => $today]),
        ]);

        return true;
    }

    public function started(string $job): void
    {
        $this->db->table('content.waiting_jobs')->where('job', $job)->update(['queued_at' => null, 'wanted_at' => null, 'wanted_now' => false]);
    }

    public function waitingSince(string $job): ?DateTimeImmutable
    {
        return Rows::moment($this->db->table('content.waiting_jobs')->where('job', $job)->value('queued_at'));
    }
}
