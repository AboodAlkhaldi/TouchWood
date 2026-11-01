<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Throwable;

/**
 * S10 (ops.md §1.6; owner, 2026-11-01): a security email failed, so every Super Admin gets a bell entry
 * saying security emails are not going out. A broken email setup would otherwise show only in the log and
 * in a FAILED row. The bell alone: email is what is failing, and the critical SMS is the gateway key's.
 *
 * **At most once an hour.** One claim per clock hour in `ops.handled_events`, taken in the same transaction
 * as the entries: one failure of a burst writes them, the others wait for it on the key and find it taken,
 * so a failure after the first costs one statement. A write that fails releases the claim, and the next
 * failure tries again.
 *
 * **Never in the way of the person's request.** Written directly, not by a job: the queue may be what is
 * down. After the caller's commit, if it has a transaction open, so it never rolls back with it. Anything
 * that breaks here is reported and stops here.
 *
 * Who: `staffToAlert` with a reserved permission, which only Super Admins hold, by each one's in-panel switch
 * for the topic, as S5 and S5b are. The topic is Payment problems, the only one reserved to Super Admins. An
 * entry needs a store: the failed message's, otherwise the first; a Super Admin's bell shows every store.
 */
final readonly class SecurityEmailAlert
{
    public function __construct(
        private Connection $db,
        private HandledEvents $handled,
        private AccessApi $access,
        private PlatformApi $platform,
        private Inbox $inbox,
        private Translator $translator,
    ) {}

    public function emailFailed(?StoreDto $store): void
    {
        $this->db->afterCommit(function () use ($store): void {
            try {
                $this->alert($store);
            } catch (Throwable $failed) {
                report($failed);
            }
        });
    }

    private function alert(?StoreDto $store): void
    {
        $now = CarbonImmutable::now();

        $this->handled->once(self::onceId($now), self::class, function () use ($store, $now): void {
            $store ??= $this->platform->allStores()[0] ?? null;

            if ($store === null) {
                return;
            }

            $word = fn (string $key, string $locale): string => (string) $this->translator->get('ops::messages.'.MessageKind::SecurityEmailsFailing->value.'.'.$key, [], $locale);

            foreach ($this->access->staffToAlert($store->storeId(), StaffNotificationTopic::PaymentProblems, OpsPermissions::SMS_COUNTRIES_MANAGE) as $staff) {
                if ($staff->byPanel) {
                    $this->inbox->add(InboxEntry::forStaff(
                        strtolower((string) Str::ulid()),
                        $staff->staffId,
                        $store->storeId(),
                        MessageKind::SecurityEmailsFailing,
                        $word('title', 'ar'),
                        $word('title', 'en'),
                        $word('line', 'ar'),
                        $word('line', 'en'),
                        null,
                        $now->toDateTimeImmutable(),
                    ));
                }
            }
        });
    }

    /** The clock hour in UTC, as the uuid `ops.handled_events` keys on. */
    public static function onceId(CarbonImmutable $at): string
    {
        $hash = substr(sha1('security_emails_failing|'.$at->utc()->format('Y-m-d H')), 0, 32);

        return implode('-', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)]);
    }
}
