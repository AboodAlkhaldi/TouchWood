<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\MarketingReach;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\WrittenEmails;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Modules\Ops\Infrastructure\Eloquent\Moment;
use Modules\Ops\Infrastructure\Queue\SendBroadcastBatchJob;
use Modules\Ops\Infrastructure\Queue\SendBroadcastEmailJob;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * Sends a notice or a staff member's message in batches (ops.md §1.7: "sending to thousands goes in
 * batches on the queue; the notice shows how many were sent").
 *
 * One batch is one transaction, on the row locked: up to 500 customers after the row's cursor - every
 * customer registered in the store, individuals and then companies (AccessApi::customerIdsInStore), the
 * members of the notice's segment (PromotionsApi::segmentMembers, store-scoped; §1.7), or the one
 * customer a message names - get their bell entry, and one queued email each that may have it;
 * the cursor and the counts move on, and the next batch is queued - the queue holds its jobs until that
 * transaction commits (`after_commit`), so a batch rolled back queues nothing. A batch that fails rolls
 * back whole and runs again from the same cursor: nobody gets a bell entry twice. Each email is its own
 * job, once by its delivery id.
 *
 * **Marketing**: a notice and a message to many email only who may receive offers (MarketingReach); a
 * message to one customer is a service message. The bell goes to every customer (owner, R4-6). A store
 * switched off still sends (owner, 2026-10-29).
 */
final readonly class BroadcastSender
{
    /** Customers a batch. */
    public const int BATCH = 500;

    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $jobs,
        private AccessApi $access,
        private PlatformApi $platform,
        private Inbox $inbox,
        private SentMessages $sent,
        private EmailSender $emails,
        private WrittenEmails $written,
        private PromotionsApi $promotions,
    ) {}

    /**
     * One batch; the next one is queued when there is more to send.
     *
     * @return bool whether more batches follow
     */
    public function batch(Broadcast $broadcast, string $id): bool
    {
        return $this->db->transaction(function () use ($broadcast, $id): bool {
            $row = $this->row($broadcast, $id, lock: true);

            if ($row === null || ! in_array($row->status, ['SENDING', 'FAILED'], true)) {
                return false;
            }

            if ($row->status === 'FAILED') {
                // Retried from the failed jobs screen: sending again, from the cursor it stopped at.
                $this->db->update("UPDATE {$this->table($broadcast)} SET status = 'SENDING' WHERE id = ?", [$row->id]);
            }

            $store = StoreId::fromString((string) $row->store_id);

            if ($row->segment_id !== null) {
                return $this->segmentBatch($broadcast, $row, $store);
            }

            $one = $row->customer_id !== null;
            $type = AccountType::from($row->cursor_type ?? AccountType::Individual->value);
            $ids = $one ? [(string) $row->customer_id] : $this->access->customerIdsInStore($store, $type, $row->cursor_after, self::BATCH);

            if ($ids === [] && $type === AccountType::Individual) {
                // Every individual reached: the companies next, from the first.
                $this->db->update("UPDATE {$this->table($broadcast)} SET cursor_type = ?, cursor_after = NULL WHERE id = ?", [AccountType::Company->value, $row->id]);
                $this->jobs->dispatch(new SendBroadcastBatchJob($broadcast, (string) $row->id));

                return true;
            }

            $bells = $ids === [] ? 0 : $this->reach($broadcast, $row, $store, $ids, offers: ! $one);
            $done = $one || $ids === [];

            $this->db->update(
                "UPDATE {$this->table($broadcast)} SET bell_count = bell_count + ?, cursor_type = ?, cursor_after = ?, status = ?, finished_at = ?::timestamptz WHERE id = ?",
                [
                    $bells,
                    $one ? null : $type->value,
                    $one || $ids === [] ? $row->cursor_after : strtolower((string) end($ids)),
                    $done ? 'SENT' : 'SENDING',
                    $done ? Moment::of(CarbonImmutable::now()->toDateTimeImmutable()) : null,
                    $row->id,
                ],
            );

            if (! $done) {
                $this->jobs->dispatch(new SendBroadcastBatchJob($broadcast, (string) $row->id));
            }

            return ! $done;
        });
    }

    /**
     * One batch of a segment's notice (§1.7): the next members after the cursor, by id, under the same
     * rules as every customer of the store - the bell to each, the email only to those who may receive
     * offers. A segment deleted meanwhile is refused by Promotions, and the batch fails as any other.
     */
    private function segmentBatch(Broadcast $broadcast, stdClass $row, StoreId $store): bool
    {
        $ids = $this->promotions->segmentMembers($store, (string) $row->segment_id, $row->cursor_after, self::BATCH);
        $bells = $ids === [] ? 0 : $this->reach($broadcast, $row, $store, $ids, offers: true);
        $done = $ids === [];

        $this->db->update(
            "UPDATE {$this->table($broadcast)} SET bell_count = bell_count + ?, cursor_type = 'SEGMENT', cursor_after = ?, status = ?, finished_at = ?::timestamptz WHERE id = ?",
            [
                $bells,
                $done ? $row->cursor_after : strtolower((string) end($ids)),
                $done ? 'SENT' : 'SENDING',
                $done ? Moment::of(CarbonImmutable::now()->toDateTimeImmutable()) : null,
                $row->id,
            ],
        );

        if (! $done) {
            $this->jobs->dispatch(new SendBroadcastBatchJob($broadcast, (string) $row->id));
        }

        return ! $done;
    }

    /**
     * One email of a batch, at the moment it leaves: the customer read again, so one who withdrew
     * their consent meanwhile gets nothing.
     *
     * @throws ProviderFailed for the queue to try again
     */
    public function email(Broadcast $broadcast, string $id, string $customerId): void
    {
        $row = $this->row($broadcast, $id, lock: false);
        $customer = $this->access->customer($customerId);

        if ($row === null || $customer === null) {
            return;
        }

        $offers = $row->customer_id === null;

        if (! ($offers ? MarketingReach::mayEmailOffers($customer) : MarketingReach::mayEmailService($customer))) {
            return;
        }

        $store = $this->store($row);
        $email = $this->written->toCustomer($broadcast->kind(), $customer, $store, self::text($row), $offers);

        $providerId = $this->emails->send($email);

        $this->sent->record($this->sentRow($broadcast, $customer->id, $store->storeId(), $providerId, SendStatus::Sent));
        $this->db->update("UPDATE {$this->table($broadcast)} SET email_count = email_count + 1 WHERE id = ?", [$row->id]);
    }

    /**
     * The queue gave up on a batch (its last attempt failed): the notice or message shows FAILED. The
     * job waits on the failed jobs screen; retrying it there carries on from the cursor.
     */
    public function batchFailed(Broadcast $broadcast, string $id): void
    {
        $this->db->update("UPDATE {$this->table($broadcast)} SET status = 'FAILED' WHERE id = ? AND status = 'SENDING'", [strtolower($id)]);
    }

    /** The queue gave up on one email: recorded as failed and counted - never lost silently (§7). */
    public function emailFailed(Broadcast $broadcast, string $id, string $customerId): void
    {
        $row = $this->row($broadcast, $id, lock: false);

        if ($row === null || $this->access->customer($customerId) === null) {
            return;
        }

        $this->db->transaction(function () use ($broadcast, $row, $customerId): void {
            $this->sent->record($this->sentRow($broadcast, $customerId, StoreId::fromString((string) $row->store_id), null, SendStatus::Failed));
            $this->db->update("UPDATE {$this->table($broadcast)} SET failed_count = failed_count + 1 WHERE id = ?", [$row->id]);
        });
    }

    /**
     * The bell entries of a batch, and its emails queued.
     *
     * @param  list<string>  $ids
     * @return int how many bell entries were made
     */
    private function reach(Broadcast $broadcast, stdClass $row, StoreId $store, array $ids, bool $offers): int
    {
        $customers = $this->access->customers($ids);
        $text = self::text($row);
        $storeDto = $this->store($row);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $bells = 0;

        foreach ($customers as $customer) {
            if ($customer->anonymized) {
                continue;
            }

            if ((bool) $row->by_bell) {
                $this->inbox->add(InboxEntry::forCustomer(
                    strtolower((string) Str::ulid()),
                    $customer->id,
                    $store,
                    $broadcast->kind(),
                    $text->titleAr,
                    $text->titleEn,
                    $text->bodyAr,
                    $text->bodyEn,
                    $text->link === null ? null : WrittenEmails::shopPath($storeDto, $customer->locale, $text->link),
                    $now,
                ));
                $bells++;
            }

            if ((bool) $row->by_email && ($offers ? MarketingReach::mayEmailOffers($customer) : MarketingReach::mayEmailService($customer))) {
                $this->jobs->dispatch(new SendBroadcastEmailJob($broadcast, (string) $row->id, strtolower($customer->id), (string) Str::uuid()));
            }
        }

        return $bells;
    }

    private function row(Broadcast $broadcast, string $id, bool $lock): ?stdClass
    {
        $columns = $broadcast === Broadcast::Notice ? 'link, NULL AS customer_id, segment_id' : 'NULL AS link, customer_id, NULL AS segment_id';
        $row = $this->db->selectOne(
            "SELECT id, store_id, title_ar, title_en, body_ar, body_en, {$columns}, by_bell, by_email, status, cursor_type, cursor_after FROM {$this->table($broadcast)} WHERE id = ?".($lock ? ' FOR UPDATE' : ''),
            [strtolower($id)],
        );

        return $row instanceof stdClass ? $row : null;
    }

    private function table(Broadcast $broadcast): string
    {
        return match ($broadcast) {
            Broadcast::Notice => 'ops.notices',
            Broadcast::StaffMessage => 'ops.staff_messages',
        };
    }

    private function store(stdClass $row): StoreDto
    {
        return $this->platform->store(StoreId::fromString((string) $row->store_id))
            ?? throw new LogicException('A notice or a message names a store Platform does not know.');
    }

    private static function text(stdClass $row): WrittenText
    {
        return WrittenText::of((string) $row->title_ar, (string) $row->title_en, (string) $row->body_ar, (string) $row->body_en, $row->link === null ? null : (string) $row->link);
    }

    private function sentRow(Broadcast $broadcast, string $customerId, StoreId $store, ?string $providerId, SendStatus $status): SentMessage
    {
        return new SentMessage(
            strtolower((string) Str::ulid()),
            $broadcast->kind(),
            strtolower($customerId),
            null,
            $store,
            MessageChannel::Email,
            $providerId,
            $status,
            CarbonImmutable::now()->toDateTimeImmutable(),
        );
    }
}
