<?php

declare(strict_types=1);

namespace Modules\Platform\Application\FailedJobs;

use DateTimeImmutable;

/**
 * One job that failed its last attempt, as Laravel keeps it in `failed_jobs` (platform.md §3, §5.6):
 * which connection and queue it ran on, what it carried, and the whole error.
 */
final readonly class FailedJob
{
    /** The error's first line, as the list shows it, at most this long. */
    public const int ERROR_LINE_MAX = 300;

    public function __construct(
        /** Laravel's uuid for it: what the screen and the log call it. */
        public string $id,
        public string $connection,
        public string $queue,
        /** The job exactly as it was queued, JSON. */
        public string $payload,
        /** The whole error, stack trace included — it can quote personal data. */
        public string $error,
        public DateTimeImmutable $failedAt,
    ) {}

    /**
     * The class that ran: a job's, or a queued listener's (Laravel's `displayName`).
     */
    public function className(): string
    {
        $name = $this->decoded()['displayName'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'unknown';
    }

    /**
     * How many tries it was allowed — Laravel keeps that, not how many it made (owner, 2026-09-29);
     * null when the job set no limit.
     */
    public function triesAllowed(): ?int
    {
        $tries = $this->decoded()['maxTries'] ?? null;

        return is_int($tries) ? $tries : null;
    }

    public function errorLine(): string
    {
        foreach (preg_split('/\R/', $this->error) ?: [] as $line) {
            if (trim($line) !== '') {
                return mb_substr(trim($line), 0, self::ERROR_LINE_MAX);
            }
        }

        return '';
    }

    /**
     * @return array<mixed>
     */
    private function decoded(): array
    {
        $payload = json_decode($this->payload, true);

        return is_array($payload) ? $payload : [];
    }
}
