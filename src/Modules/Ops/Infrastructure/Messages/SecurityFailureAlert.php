<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Throwable;

/**
 * S10 and S10b (ops.md §1.6; owner, 2026-11-01): a security email or SMS could not be sent, so every active
 * Super Admin gets a bell entry. A broken setup would otherwise show only in the log and in a FAILED row. The
 * bell alone: a failure never sends a message about itself. The words announce no outage: one failure may be
 * one bad address.
 *
 * **At most once an hour, per channel.** One claim per clock hour and kind in `ops.handled_events`, taken in
 * the same transaction as the entries: one failure of a burst writes them, and the others find the hour taken,
 * so a failure after the first costs two statements. A write that fails releases the claim, and the next
 * failure tries again.
 *
 * **Never in the way of the person's request.** Written directly, not by a job: the queue may be what is
 * down. After the caller's commit, if it has a transaction open, so it never rolls back with it. A request
 * waits on another's uncommitted claim for WAIT at most, then leaves it to them. Anything else that breaks here
 * is reported and stops here.
 *
 * **No one can switch it off** (owner, 2026-11-01). `staffToAlert` with a reserved permission gives every active
 * Super Admin and no one else, whatever their switches. It insists on a topic only to say each one's switches
 * for it, and those are not read here, so the topic named is of no consequence. An entry needs a store: the
 * failed message's, otherwise the first, on or off; a Super Admin's bell shows every store.
 */
final readonly class SecurityFailureAlert
{
    /** How long a request waits on another's claim of the hour. */
    private const string WAIT = '250ms';

    public function __construct(
        private Connection $db,
        private HandledEvents $handled,
        private AccessApi $access,
        private PlatformApi $platform,
        private Inbox $inbox,
        private Translator $translator,
    ) {}

    public function failed(MessageChannel $channel, ?StoreDto $store): void
    {
        $kind = $channel === MessageChannel::Sms ? MessageKind::SecuritySmsFailed : MessageKind::SecurityEmailFailed;

        $this->db->afterCommit(function () use ($kind, $store): void {
            try {
                $this->alert($kind, $store);
            } catch (Throwable $failed) {
                report($failed);
            }
        });
    }

    private function alert(MessageKind $kind, ?StoreDto $store): void
    {
        $now = CarbonImmutable::now();

        try {
            $this->db->transaction(function () use ($kind, $store, $now): void {
                $this->db->statement("SET LOCAL lock_timeout = '".self::WAIT."'");
                $this->handled->once(self::onceId($kind, $now), self::class, fn () => $this->tell($kind, $store, $now));
            });
        } catch (QueryException $failed) {
            // 55P03: another request holds the hour's claim and is telling them. Any other wait is a failure.
            if ($failed->getCode() !== '55P03' || ! str_contains($failed->getSql(), 'handled_events')) {
                throw $failed;
            }
        }
    }

    private function tell(MessageKind $kind, ?StoreDto $store, CarbonImmutable $now): void
    {
        $store ??= $this->platform->allStores()[0] ?? null;

        if ($store === null) {
            return;
        }

        $word = fn (string $key, string $locale): string => (string) $this->translator->get("ops::messages.{$kind->value}.{$key}", [], $locale);

        foreach ($this->access->staffToAlert($store->storeId(), StaffNotificationTopic::NewOrders, OpsPermissions::SMS_COUNTRIES_MANAGE) as $staff) {
            $this->inbox->add(InboxEntry::forStaff(
                strtolower((string) Str::ulid()),
                $staff->staffId,
                $store->storeId(),
                $kind,
                $word('title', 'ar'),
                $word('title', 'en'),
                $word('line', 'ar'),
                $word('line', 'en'),
                null,
                $now->toDateTimeImmutable(),
            ));
        }
    }

    /** The kind and the clock hour in UTC, as the uuid `ops.handled_events` keys on. */
    public static function onceId(MessageKind $kind, CarbonImmutable $at): string
    {
        $hash = substr(sha1($kind->value.'|'.$at->utc()->format('Y-m-d H')), 0, 32);

        return implode('-', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)]);
    }
}
