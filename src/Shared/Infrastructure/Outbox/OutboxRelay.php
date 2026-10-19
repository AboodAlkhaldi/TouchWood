<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use stdClass;
use Throwable;

/**
 * The outbox's relay (sales.md §5; owner, answer 40): every minute, from one server, the messages whose
 * time has come, in the order they were inserted. Each is **claimed** first — its attempt counted and
 * its `available_at` moved a lease ahead under `FOR UPDATE SKIP LOCKED`, and that committed — so two
 * relays never take one row, and a relay killed in the middle of a dispatch leaves the attempt counted
 * and the row to the next after the lease. Then dispatched, and marked. A dispatch that fails keeps the
 * error and waits longer each time — 1, 2, 4 … minutes, at most an hour — **never dropped**; from the
 * tenth attempt each failure is logged as an error for someone to look at. Dispatched rows are kept
 * 30 days.
 *
 * **One order's events keep their order**: a message is not taken while an earlier one of the same
 * order (its payload's `orderId`) is still undispatched, so a failed `OrderPlaced` holds back that
 * order's `OrderCancelled`, never another order's events.
 *
 * At least once: a relay that dispatched and died before marking sends it again, and a dispatch that
 * failed after some listeners ran sends it to them all again — every consumer is idempotent
 * (`ProcessedEvents`). A queued listener counts as delivered once its job is on the queue: a failure
 * there lands in `failed_jobs`, as any queued work does.
 */
final class OutboxRelay implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

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

    /** One try: the next minute's run takes whatever this one left (platform.md §3). */
    public int $tries = 1;

    /** Never two runs at once on the queue, the lease covering the rest. */
    public int $uniqueFor = 120;

    /** Well inside the lease, so a run never outlives its claim. */
    public int $timeout = 240;

    public function handle(ConnectionInterface $db, Dispatcher $events): void
    {
        $now = CarbonImmutable::now();

        foreach ($this->claim($db, $now) as $row) {
            try {
                $events->dispatch(self::rebuild((string) $row->type, (string) $row->payload));
                $db->table('public.outbox_messages')->where('id', $row->id)->whereNull('dispatched_at')->update(['dispatched_at' => CarbonImmutable::now(), 'last_error' => null]);
            } catch (Throwable $failed) {
                // The attempt was counted at the claim.
                $attempts = (int) $row->attempts + 1;
                $db->table('public.outbox_messages')->where('id', $row->id)->update([
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
     * The messages whose time has come, at most a batch, in insert order — none while an earlier one of
     * its order is undispatched — each with its attempt counted and leased ahead, committed before any is
     * dispatched, so another relay skips them now and later. The rows as they were before the claim.
     *
     * @return list<stdClass>
     */
    public function claim(ConnectionInterface $db, CarbonImmutable $now): array
    {
        return $db->transaction(function () use ($db, $now): array {
            /** @var list<stdClass> $rows */
            $rows = $db->table('public.outbox_messages as m')
                ->whereNull('m.dispatched_at')
                ->where('m.available_at', '<=', $now)
                ->whereNotExists(static fn ($earlier) => $earlier->selectRaw('1')
                    ->from('public.outbox_messages as e')
                    ->whereNull('e.dispatched_at')
                    ->whereColumn('e.id', '<', 'm.id')
                    ->whereRaw("e.payload->>'orderId' = m.payload->>'orderId'"))
                ->orderBy('m.id')
                ->limit(self::BATCH)
                ->lock('FOR UPDATE OF m SKIP LOCKED')
                ->get(['m.*'])
                ->all();

            if ($rows !== []) {
                $db->table('public.outbox_messages')->whereIn('id', array_map(static fn (stdClass $row): int => (int) $row->id, $rows))
                    ->update(['available_at' => $now->addSeconds(self::LEASE), 'attempts' => $db->raw('attempts + 1')]);
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
     * The event again, from its class and what was written: each constructor parameter by its name —
     * a parameter with a default keeps it when nothing was written for it — and a moment back into the
     * date class the parameter names.
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
            $name = $parameter->getName();

            if (! array_key_exists($name, $values)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                throw new LogicException("The outbox's {$type} has no {$name}.");
            }

            $value = $values[$name];
            $kind = $parameter->getType();

            if ($kind instanceof ReflectionNamedType && is_string($value) && is_a($kind->getName(), DateTimeInterface::class, true)) {
                $dateClass = $kind->getName();
                $value = $dateClass === DateTimeInterface::class || (new ReflectionClass($dateClass))->isAbstract() ? new DateTimeImmutable($value) : new $dateClass($value);
            }

            $arguments[$name] = $value;
        }

        return $class->newInstanceArgs($arguments);
    }
}
