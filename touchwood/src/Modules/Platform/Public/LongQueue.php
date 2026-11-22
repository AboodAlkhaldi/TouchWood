<?php

declare(strict_types=1);

namespace Modules\Platform\Public;

/**
 * The queue connection for a job that may run longer than the normal connection lets it (owner,
 * 2026-11-10): `config/queue.php`'s `long_connection`, `database-long` unless set. A database queue
 * hands a job to a second worker once it has been reserved longer than its connection's
 * `retry_after`; the normal one's is 330 s, the long one's 3700 s.
 *
 * A job takes it in its constructor, `$this->onConnection(LongQueue::connection())`: `Queueable`
 * declares `$connection` already, so a job cannot give it another default. Platform's
 * `QueueConnectionsTest` finds every queued class whose timeout needs it.
 */
final class LongQueue
{
    public const string CONNECTION = 'database-long';

    public static function connection(): string
    {
        $name = config('queue.long_connection');

        return is_string($name) && $name !== '' ? $name : self::CONNECTION;
    }
}
