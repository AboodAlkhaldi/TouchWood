<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use stdClass;
use Throwable;

/**
 * The outbox's relay (sales.md §5; owner, answer 40): every minute, from one server, the messages whose
 * time has come, in the order written. Each is **claimed** first — its `available_at` moved a lease
 * ahead under `FOR UPDATE SKIP LOCKED`, and that committed — so two relays never take one row and a
 * relay that dies leaves it to the next after the lease; then dispatched, and marked. A dispatch that
 * fails counts the attempt, keeps the error and waits longer each time — 1, 2, 4 … minutes, at most an
 * hour — **never dropped**; from the tenth attempt each failure is logged as an error for someone to
 * look at. Dispatched rows are kept 30 days.
 *
 * At least once: a relay that dispatched and died before marking sends it again; every consumer is
 * idempotent (`ProcessedEvents`).
 */
final class OutboxRelay implements ShouldQueue
{
    /** Messages one run takes. */
    public const int BATCH = 100;

    /** How long a claimed message waits before another relay may take it, in seconds. */
    public const int LEASE = 300;

    /** The longest wait between two attempts, in minutes. */
    public const int MOST_WAIT = 60;

    /** From this attempt on, a failure is logged as an error. */
    public const int LOUD_FROM = 10;

    /** Dispatched rows kept this many days. */
    public const int KEEP_DAYS = 30;

    public function handle(ConnectionInterface $db, Dispatcher $events): void
    {
        $now = CarbonImmutable::now();

        foreach ($this->claim($db, $now) as $row) {
            try {
                $events->dispatch(self::rebuild((string) $row->type, (string) $row->payload));
                $db->table('public.outbox_messages')->where('id', $row->id)->update(['dispatched_at' => CarbonImmutable::now(), 'last_error' => null]);
            } catch (Throwable $failed) {
                $attempts = (int) $row->attempts + 1;
                $db->table('public.outbox_messages')->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'last_error' => mb_substr($failed::class.': '.$failed->getMessage(), 0, 2000),
                    'available_at' => CarbonImmutable::now()->addMinutes(self::wait($attempts)),
                ]);

                if ($attempts >= self::LOUD_FROM) {
                    Log::error('An outbox message keeps failing.', ['event_id' => $row->event_id, 'type' => $row->type, 'attempts' => $attempts, 'error' => $failed->getMessage()]);
                }
            }
        }

        $db->table('public.outbox_messages')->whereNotNull('dispatched_at')->where('dispatched_at', '<', $now->subDays(self::KEEP_DAYS))->delete();
    }

    /**
     * The messages whose time has come, at most a batch, in the order written — each leased ahead and
     * that committed before any is dispatched, so another relay skips them now and later.
     *
     * @return list<stdClass>
     */
    public function claim(ConnectionInterface $db, CarbonImmutable $now): array
    {
        return $db->transaction(function () use ($db, $now): array {
            /** @var list<stdClass> $rows */
            $rows = $db->table('public.outbox_messages')
                ->whereNull('dispatched_at')
                ->where('available_at', '<=', $now)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get()
                ->all();

            if ($rows !== []) {
                $db->table('public.outbox_messages')->whereIn('id', array_map(static fn (stdClass $row): int => (int) $row->id, $rows))
                    ->update(['available_at' => $now->addSeconds(self::LEASE)]);
            }

            return $rows;
        });
    }

    /** Minutes before the next attempt: doubling from one, at most an hour. */
    public static function wait(int $attempts): int
    {
        return min(self::MOST_WAIT, 2 ** min(max($attempts - 1, 0), 6));
    }

    /**
     * The event again, from its class and what was written: each constructor parameter by its name, a
     * moment back into one.
     */
    private static function rebuild(string $type, string $payload): object
    {
        if (! class_exists($type)) {
            throw new LogicException("The outbox holds an event of a class that no longer exists: {$type}.");
        }

        /** @var array<string, mixed> $values */
        $values = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $class = new ReflectionClass($type);
        $arguments = [];

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $value = $values[$parameter->getName()] ?? null;
            $kind = $parameter->getType();

            if ($kind instanceof ReflectionNamedType && is_a($kind->getName(), \DateTimeInterface::class, true) && is_string($value)) {
                $value = new DateTimeImmutable($value);
            }

            $arguments[$parameter->getName()] = $value;
        }

        return $class->newInstanceArgs($arguments);
    }
}
