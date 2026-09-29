<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\Platform\Application\FailedJobs\FailedJob;
use Modules\Platform\Application\FailedJobs\FailedJobs;
use Modules\Platform\Application\FailedJobs\FailedJobSummary;
use stdClass;

/**
 * Laravel's `failed_jobs` (platform.md §5.6), read and emptied one job at a time.
 *
 * A retry does for the database queue what `queue:retry` does — the payload pushed back raw on its
 * own connection and queue, its attempts counted afresh — except that it runs in the caller's
 * transaction, beside the delete of the row, and fires no `JobRetryRequested` (nothing here listens
 * for it). No job here sets `retryUntil` (a time limit in place of tries); one that did would need
 * that limit refreshed on retry, as `queue:retry` does by rebuilding the job.
 */
final readonly class DatabaseFailedJobs implements FailedJobs
{
    /** config/queue.php, `failed.table`. */
    private const string TABLE = 'failed_jobs';

    /** Enough of an error to find its first line, without reading its whole stack trace. */
    private const int ERROR_HEAD = 2000;

    public function __construct(
        private ConnectionInterface $db,
        private QueueFactory $queue,
        private Config $config,
    ) {}

    public function page(?DateTimeImmutable $afterFailedAt, ?string $afterId, int $limit): array
    {
        $query = $this->table()
            ->selectRaw("uuid, connection, queue, failed_at, payload::json ->> 'displayName' as display_name, payload::json ->> 'maxTries' as max_tries, left(exception, ?) as error_head", [self::ERROR_HEAD])
            ->orderBy('failed_at')
            ->orderBy('uuid')
            ->limit($limit);

        if ($afterFailedAt !== null && $afterId !== null) {
            $query->whereRaw('(failed_at, uuid) > (?, ?)', [CarbonImmutable::instance($afterFailedAt)->utc()->format('Y-m-d H:i:s'), $afterId]);
        }

        return array_values(array_map(
            static fn (stdClass $row): FailedJobSummary => new FailedJobSummary(
                (string) $row->uuid,
                (string) $row->connection,
                (string) $row->queue,
                is_string($row->display_name) && $row->display_name !== '' ? $row->display_name : 'unknown',
                is_string($row->max_tries) && ctype_digit($row->max_tries) ? (int) $row->max_tries : null,
                FailedJob::firstLine((string) $row->error_head),
                self::failedAt($row),
            ),
            $query->get()->all(),
        ));
    }

    public function find(string $id): ?FailedJob
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = $this->table()->where('uuid', $id)->first();

        return $row === null ? null : self::toFailedJob($row);
    }

    public function lock(string $id): ?FailedJob
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = $this->table()->where('uuid', $id)->lockForUpdate()->first();

        return $row === null ? null : self::toFailedJob($row);
    }

    public function retryable(string $connection): bool
    {
        $settings = $this->config->get("queue.connections.{$connection}");

        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'database') {
            return false;
        }

        // The queue's own database connection, or the application's when it names none: the one
        // this class writes through, so the push and the delete commit together.
        $database = $settings['connection'] ?? null;

        return ($database ?? $this->config->get('database.default')) === $this->config->get('database.default');
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
            self::failedAt($row),
        );
    }

    /** A timestamp without a zone, written in the application's, which is UTC. */
    private static function failedAt(stdClass $row): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $row->failed_at, 'UTC');
    }
}
