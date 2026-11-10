<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Repository\WaitingJobs;
use stdClass;

/**
 * `WaitingJobs` in SQL (`content.waiting_jobs`): one row a job, written by the migration - and again by the first
 * ask, should it be missing. A job queued more than `STALE_HOURS` ago is taken as lost (its worker died, the queue
 * was cleared): it no longer waits, so the button works again and the next ask queues one (PR 4 review).
 */
final readonly class DatabaseWaitingJobs implements WaitingJobs
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function ask(string $job, DateTimeImmutable $at): bool
    {
        $row = $this->locked($job);

        if (! $row instanceof stdClass || self::waits($row->queued_at, $at)) {
            return false;
        }

        $this->db->table('content.waiting_jobs')->where('job', $job)->update(['queued_at' => Rows::at($at)]);

        return true;
    }

    public function want(string $job, DateTimeImmutable $at, bool $now): void
    {
        $this->db->insert(
            'INSERT INTO content.waiting_jobs (job, wanted_at, wanted_now) VALUES (?, ?, '.($now ? 'true' : 'false').')'
            .' ON CONFLICT (job) DO UPDATE SET wanted_at = COALESCE(content.waiting_jobs.wanted_at, EXCLUDED.wanted_at),'
            .' wanted_now = content.waiting_jobs.wanted_now OR EXCLUDED.wanted_now',
            [$job, Rows::at($at)],
        );
    }

    public function takeWanted(string $job, DateTimeImmutable $at, string $today): bool
    {
        $row = $this->locked($job);

        if (! $row instanceof stdClass || self::waits($row->queued_at, $at) || $row->wanted_at === null) {
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
        $queued = $this->db->table('content.waiting_jobs')->where('job', $job)->value('queued_at');

        return self::waits($queued, Moments::now()) ? Rows::moment($queued) : null;
    }

    /** The job's row, made if missing, locked until the transaction ends. */
    private function locked(string $job): ?stdClass
    {
        $this->db->insert('INSERT INTO content.waiting_jobs (job) VALUES (?) ON CONFLICT (job) DO NOTHING', [$job]);
        $row = $this->db->selectOne('SELECT queued_at, wanted_at, wanted_now, extra_on FROM content.waiting_jobs WHERE job = ? FOR UPDATE', [$job]);

        return $row instanceof stdClass ? $row : null;
    }

    /** Whether a job queued then still waits at `$at`: queued, and not so long ago that it is taken as lost. */
    private static function waits(mixed $queuedAt, DateTimeImmutable $at): bool
    {
        $queued = Rows::moment($queuedAt);

        return $queued !== null && $queued > $at->modify('-'.self::STALE_HOURS.' hours');
    }
}
