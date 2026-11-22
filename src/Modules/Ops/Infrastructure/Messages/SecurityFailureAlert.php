<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as Views;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
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
 * S10 and S10b (ops.md §1.6; owner, 2026-11-01): a security email or a code by WhatsApp could not be sent, so every
 * active Super Admin gets a bell entry. A broken setup would otherwise show only in the log and in a FAILED row. The
 * bell alone for an email: a failure never sends a message about itself. For WhatsApp, **and email too** (§9.8 W3):
 * WhatsApp is what is down, not email. The words announce no outage: one failure may be one bad address.
 *
 * **At most once an hour, per channel.** One claim per clock hour and kind in `ops.handled_events`, taken in
 * the same transaction as the entries: one failure of a burst writes them, and the others find the hour taken,
 * so a failure after the first costs two queries. A write that fails releases the claim, and the next
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
 *
 * **S10c by the same rules** (queuedEmailRefused): a queued email Brevo refused for everybody.
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
        private EmailSender $emails,
        private Views $views,
        private Config $config,
    ) {}

    /**
     * A security message could not be sent. **One number's lack of WhatsApp is no alert** (ops.md §9.8 point 3):
     * Meta refused that person alone, `$why` says so, and the person waiting uses Resend. A setup failure - a missing
     * setting, a token, a template - tells the Super Admins, and so does anything else that broke on the way.
     */
    public function failed(MessageChannel $channel, ?StoreDto $store, ?Throwable $why = null): void
    {
        $byPhone = in_array($channel, [MessageChannel::Sms, MessageChannel::Whatsapp], true);

        if ($byPhone && $why instanceof ProviderFailed && $why->kind === FailureKind::OneAddress) {
            return;
        }

        $this->raise($byPhone ? MessageKind::SecuritySmsFailed : MessageKind::SecurityEmailFailed, $store);
    }

    /**
     * S10c (owner, Content round 3 #20; ops.md §9.6 #3): a queued email that Brevo refused for everybody - a bad key, no
     * credit, the sender - waits up to a day with its row QUEUED, so the Super Admins are told, by the same rules as
     * S10: every active one, no switch, the bell alone, at most once a clock hour (its own hour). Raised by
     * ProviderFailureRules from a worker. Filed under the first store (the builder's pick): the refusal is the
     * provider's, the same for every store, and a Super Admin's bell shows every store.
     */
    public function queuedEmailRefused(): void
    {
        $this->raise(MessageKind::QueuedEmailRefused, null);
    }

    private function raise(MessageKind $kind, ?StoreDto $store): void
    {
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

        $told = [];

        try {
            $this->db->transaction(function () use ($kind, $store, $now, &$told): void {
                $this->db->statement("SET LOCAL lock_timeout = '".self::WAIT."'");
                $this->handled->once(self::onceId($kind, $now), self::class, function () use ($kind, $store, $now, &$told): void {
                    $told = $this->tell($kind, $store, $now);
                });
            });
        } catch (QueryException $failed) {
            // 55P03: another request holds the hour's claim and is telling them. Any other wait is a failure.
            if ($failed->getCode() !== '55P03' || ! str_contains($failed->getSql(), 'handled_events')) {
                throw $failed;
            }
        }

        // W3: WhatsApp is what is down, so the email is not (the bell alone for every other failure). Sent after the
        // claim has committed, never inside it: a mail provider's wait must not hold the hour's lock.
        if ($kind === MessageKind::SecuritySmsFailed) {
            $this->email($told);
        }
    }

    /**
     * The Super Admins told, by their bell.
     *
     * @return list<string> their staff ids, for the email
     */
    private function tell(MessageKind $kind, ?StoreDto $store, CarbonImmutable $now): array
    {
        $store ??= $this->platform->allStores()[0] ?? null;

        if ($store === null) {
            return [];
        }

        $told = [];
        $word = fn (string $key, string $locale): string => (string) $this->translator->get("ops::messages.{$kind->value}.{$key}", [], $locale);

        foreach ($this->access->staffToAlert($store->storeId(), StaffNotificationTopic::NewOrders, OpsPermissions::SMS_COUNTRIES_MANAGE) as $staff) {
            $told[] = $staff->staffId;
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

        return $told;
    }

    /**
     * The same words by email to each Super Admin told, in their own language (§9.8 W3). One that cannot be sent
     * is reported and the others still go; it never reaches the person whose request failed.
     *
     * @param  list<string>  $staffIds
     */
    private function email(array $staffIds): void
    {
        $root = $this->config->get('app.url');

        foreach ($staffIds as $id) {
            try {
                $staff = $this->access->staff($id);

                if ($staff === null) {
                    continue;
                }

                $word = fn (string $key): string => (string) $this->translator->get("ops::messages.security_sms_failed.{$key}", [], $staff->locale);
                $lines = [$word('line')];
                $brand = (string) $this->config->get('mail.from.name');

                $this->emails->send(new OutgoingEmail(
                    (string) $this->config->get('mail.from.address'),
                    $brand,
                    $staff->email,
                    trim($staff->firstName.' '.$staff->lastName),
                    $word('title'),
                    $this->views->make('ops::mail.security', [
                        'lines' => $lines,
                        'action' => null,
                        'link' => null,
                        'locale' => $staff->locale,
                        'brand' => $brand,
                        'logo' => (is_string($root) ? rtrim($root, '/') : '').'/'.OpsSecurityMessages::LOGO,
                        'direction' => $staff->locale === 'ar' ? 'rtl' : 'ltr',
                    ])->render(),
                    implode("\n\n", $lines),
                ));
            } catch (Throwable $failed) {
                report($failed);
            }
        }
    }

    /** The kind and the clock hour in UTC, as the uuid `ops.handled_events` keys on. */
    public static function onceId(MessageKind $kind, CarbonImmutable $at): string
    {
        $hash = substr(sha1($kind->value.'|'.$at->utc()->format('Y-m-d H')), 0, 32);

        return implode('-', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)]);
    }
}
