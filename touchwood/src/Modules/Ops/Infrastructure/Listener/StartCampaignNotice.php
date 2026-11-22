<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Content\Public\Events\CampaignWentLive;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Application\Delivery\BroadcastQueue;
use Modules\Ops\Domain\Repository\Notices;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Psr\Log\LoggerInterface;

/**
 * Sends the notice a campaign carries when the campaign goes live (ops.md §1.7, §9.7 #1): as a scheduled notice does
 * when its time comes, by the same batches. Content never calls Ops.
 *
 * Only a notice still waiting starts - a draft, the campaign's own kind, or one waiting for its time - under its row's
 * lock, so the scheduler or a staff member's cancel gets there first or waits: no notice named, one gone, cancelled,
 * already started, or another store's sends nothing, the last logged by its ids. A notice with a time keeps it, and
 * whichever comes first starts it.
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`), in one transaction with the
 * start and its first batch.
 */
final class StartCampaignNotice implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly Notices $notices,
        private readonly BroadcastQueue $queue,
        private readonly HandledEvents $handled,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(CampaignWentLive $event): void
    {
        $noticeId = $event->noticeId;

        if ($noticeId === null) {
            return;
        }

        $this->handled->once($event->eventId, self::class, function () use ($event, $noticeId): void {
            $notice = $this->notices->lock($noticeId);

            if ($notice === null || ! $notice->waits()) {
                return;
            }

            if ($notice->store->value !== strtolower($event->storeId)) {
                $this->log->warning('Ops started no notice: the campaign names another store\'s notice.', [
                    'campaign_id' => strtolower($event->campaignId),
                    'event_store_id' => strtolower($event->storeId),
                    'notice_id' => $notice->id,
                    'notice_store_id' => $notice->store->value,
                ]);

                return;
            }

            $this->notices->update($notice->started(CarbonImmutable::now()->toDateTimeImmutable()));
            $this->queue->start(Broadcast::Notice, $notice->id);
        });
    }
}
