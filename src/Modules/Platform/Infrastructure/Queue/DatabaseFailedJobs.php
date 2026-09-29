<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Platform\Application\FailedJobs\FailedJob;
use Modules\Platform\Application\FailedJobs\FailedJobs;
use stdClass;

/**
 * Laravel's `failed_jobs` (platform.md §5.6), read and emptied one job at a time.
 *
 * A retry does what `queue:retry` does — the payload pushed back raw on its own connection and queue,
 * its attempts counted afresh — except that it runs in the caller's transaction, beside the delete
 * of the row. No job here sets `retryUntil` (a time limit in place of tries); one that did would need
 * that limit refreshed on retry, as `queue:retry` does by rebuilding the job.
 */
final readonly class DatabaseFailedJobs implements FailedJobs
{
    /** config/queue.php, `failed.table`. */
    private const string TABLE = 'failed_jobs';

    public function __construct(
        private ConnectionInterface $db,
        private QueueFactory $queue,
    ) {}

    public function all(): array
    {
        return array_values(array_map(
            fn (stdClass $row): FailedJob => self::toFailedJob($row),
            $this->table()->orderBy('failed_at')->orderBy('id')->get()->all(),
        ));
    }

    public function find(string $id): ?FailedJob
    {
        $row = $this->table()->where('uuid', $id)->first();

        return $row === null ? null : self::toFailedJob($row);
    }

    public function lock(string $id): ?FailedJob
    {
        $row = $this->table()->where('uuid', $id)->lockForUpdate()->first();

        return $row === null ? null : self::toFailedJob($row);
    }

    public function requeue(FailedJob $job): void
    {
        $this->queue->connection($job->connection)->pushRaw(self::attemptsReset($job->payload), $job->queue);
    }

    public function forget(string $id): void
    {
        $this->table()->where('uuid', $id)->delete();
    }

    public function count(): int
    {
        return $this->table()->count();
    }

    private function table(): Builder
    {
        return $this->db->table(self::TABLE);
    }

    /**
     * A queue that counts attempts inside the payload starts it again at none; the database queue
     * counts them in its own column, which a new row starts at none anyway.
     */
    private static function attemptsReset(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! isset($decoded['attempts'])) {
            return $payload;
        }

        $decoded['attempts'] = 0;

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    private static function toFailedJob(stdClass $row): FailedJob
    {
        return new FailedJob(
            (string) $row->uuid,
            (string) $row->connection,
            (string) $row->queue,
            (string) $row->payload,
            (string) $row->exception,
            // A timestamp without a zone, written in the application's, which is UTC.
            CarbonImmutable::parse((string) $row->failed_at, 'UTC'),
        );
    }
}
