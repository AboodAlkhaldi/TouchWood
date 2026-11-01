<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffDisplayNameDto;
use Modules\Ops\Application\Query\FindCustomersToMessage\CustomerToMessage;
use Modules\Ops\Application\Query\ListNotices\NoticesPage;
use Modules\Ops\Application\Query\ListSegments\SegmentsPage;
use Modules\Ops\Application\Query\ListStaffMessages\StaffMessageRow;
use Modules\Ops\Application\Query\ListStaffMessages\StaffMessagesPage;
use Modules\Ops\Application\Query\ListSubscribers\SubscribersPage;
use Modules\Ops\Domain\Model\BroadcastStatus;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\SegmentChoiceDto;
use Shared\Domain\ValueObject\StoreId;

/** Notices, the composer's customers, the staff messages and the subscribers as the screens get them (ops.md §4). */
final readonly class WrittenPages
{
    public function __construct(
        private PlatformApi $platform,
        private AccessApi $access,
        private PromotionsApi $promotions,
    ) {}

    public function notices(NoticesPage $page): NoticesPageData
    {
        $writers = $this->names(array_map(static fn (Notice $notice): string => $notice->createdBy, $page->notices));
        $segments = $this->segmentsOf(array_map(static fn (Notice $notice): array => [$notice->store, $notice->segmentId], $page->notices));

        return new NoticesPageData(array_map(fn (Notice $notice): NoticeData => $this->written($notice, $writers, $segments), $page->notices), $page->next);
    }

    public function notice(Notice $notice): NoticeData
    {
        return $this->written($notice, $this->names([$notice->createdBy]), $this->segmentsOf([[$notice->store, $notice->segmentId]]));
    }

    public function staffMessages(StaffMessagesPage $page): StaffMessagesPageData
    {
        $senders = $this->names(array_map(static fn (StaffMessageRow $row): string => $row->senderId, $page->messages));
        $segments = $this->segmentsOf(array_map(static fn (StaffMessageRow $row): array => [StoreId::fromString($row->storeId), $row->segmentId], $page->messages));
        $customers = [];

        foreach ($this->access->customers(array_values(array_unique(array_filter(array_map(static fn (StaffMessageRow $row): ?string => $row->customerId, $page->messages))))) as $customer) {
            $customers[strtolower($customer->id)] = trim($customer->firstName.' '.$customer->lastName);
        }

        return new StaffMessagesPageData(array_map(function (StaffMessageRow $row) use ($senders, $customers, $segments): StaffMessageData {
            $store = $this->platform->store(StoreId::fromString($row->storeId));
            $segment = $row->segmentId === null ? null : ($segments[strtolower($row->segmentId)] ?? null);

            return new StaffMessageData(
                $row->id,
                $store === null ? '' : $store->code,
                $row->audience,
                $row->customerId,
                $row->customerId === null ? null : ($customers[$row->customerId] ?? ''),
                $row->segmentId,
                $segment?->nameAr,
                $segment?->nameEn,
                $segment?->memberCount,
                $row->titleAr,
                $row->titleEn,
                $row->bodyAr,
                $row->bodyEn,
                $row->byBell,
                $row->byEmail,
                $row->status,
                $senders[strtolower($row->senderId)] ?? '',
                $row->bellCount,
                $row->emailCount,
                $row->failedCount,
                self::utc($row->createdAt),
                $row->finishedAt === null ? null : self::utc($row->finishedAt),
            );
        }, $page->messages), $page->next);
    }

    /**
     * @param  array<string, string>  $writers  by staff id, lower-cased
     * @param  array<string, SegmentChoiceDto>  $segments  by segment id, lower-cased
     */
    private function written(Notice $notice, array $writers, array $segments): NoticeData
    {
        $store = $this->platform->store($notice->store);
        $segment = $notice->segmentId === null ? null : ($segments[strtolower($notice->segmentId)] ?? null);
        $zone = new DateTimeZone($store === null ? 'UTC' : $store->timezone);

        return new NoticeData(
            $notice->id,
            $store === null ? '' : $store->code,
            $notice->text->titleAr,
            $notice->text->titleEn,
            $notice->text->bodyAr,
            $notice->text->bodyEn,
            $notice->text->link,
            $notice->byBell,
            $notice->byEmail,
            $notice->status->value,
            self::utc($notice->sendAt),
            $notice->sendAt->setTimezone($zone)->format('Y-m-d H:i'),
            $notice->status === BroadcastStatus::Scheduled,
            $notice->bellCount,
            $notice->emailCount,
            $notice->failedCount,
            self::utc($notice->createdAt),
            $notice->segmentId,
            $segment?->nameAr,
            $segment?->nameEn,
            $segment?->memberCount,
            $writers[strtolower($notice->createdBy)] ?? '',
            $notice->startedAt === null ? null : self::utc($notice->startedAt),
            $notice->finishedAt === null ? null : self::utc($notice->finishedAt),
        );
    }

    /**
     * The segments a page names, each read once however many rows name it: `PromotionsApi` has a read of one
     * segment and none of several, so a page of 20 costs at most 20 reads (ops.md §4.4). One deleted since,
     * or no longer the store's, is left out.
     *
     * @param  list<array{0: StoreId, 1: string|null}>  $rows  each row's store and segment
     * @return array<string, SegmentChoiceDto> by segment id, lower-cased
     */
    private function segmentsOf(array $rows): array
    {
        $segments = [];
        $asked = [];

        foreach ($rows as [$store, $segmentId]) {
            if ($segmentId === null || isset($asked[strtolower($segmentId)])) {
                continue;
            }

            $asked[strtolower($segmentId)] = true;
            $segment = $this->promotions->storeSegment($store, $segmentId);

            if ($segment !== null) {
                $segments[strtolower($segmentId)] = $segment;
            }
        }

        return $segments;
    }

    /**
     * How staff are named to the person reading (access.md amendment 54).
     *
     * @param  list<string>  $staffIds
     * @return array<string, string> by staff id, lower-cased
     */
    private function names(array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        return array_map(static fn (StaffDisplayNameDto $name): string => $name->name, $this->access->staffDisplayNames(array_values(array_unique($staffIds))));
    }

    public function segments(SegmentsPage $page): SegmentsPageData
    {
        return new SegmentsPageData(array_map(static fn (SegmentChoiceDto $segment): SegmentChoiceData => new SegmentChoiceData(
            $segment->id,
            $segment->nameAr,
            $segment->nameEn,
            $segment->memberCount,
        ), $page->segments), $page->next);
    }

    /**
     * @param  list<CustomerToMessage>  $customers
     * @return list<CustomerToMessageData>
     */
    public function customers(array $customers): array
    {
        return array_map(static fn (CustomerToMessage $customer): CustomerToMessageData => new CustomerToMessageData(
            $customer->id,
            $customer->firstName,
            $customer->lastName,
            $customer->email,
            $customer->phone,
            $customer->homeStoreCode,
        ), $customers);
    }

    public function subscribers(SubscribersPage $page): SubscribersPageData
    {
        return new SubscribersPageData(array_map(static fn (Subscriber $subscriber): SubscriberData => new SubscriberData(
            $subscriber->id,
            in_array($subscriber->id, $page->hiddenEmails, true) ? null : $subscriber->email,
            $subscriber->locale,
            $subscriber->isGuest() ? 'GUEST' : 'CUSTOMER',
            $subscriber->isConfirmed(),
            self::utc($subscriber->createdAt),
            $subscriber->confirmedAt === null ? null : self::utc($subscriber->confirmedAt),
        ), $page->subscribers), $page->next);
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP');
    }
}
