<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffDisplayNameDto;
use Modules\Ops\Application\Query\FindCustomersToMessage\CustomerToMessage;
use Modules\Ops\Application\Query\ListNotices\NoticesPage;
use Modules\Ops\Application\Query\ListStaffMessages\StaffMessageRow;
use Modules\Ops\Application\Query\ListStaffMessages\StaffMessagesPage;
use Modules\Ops\Application\Query\ListSubscribers\SubscribersPage;
use Modules\Ops\Domain\Model\BroadcastStatus;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/** Notices, the composer's customers, the staff messages and the subscribers as the screens get them (ops.md §4). */
final readonly class WrittenPages
{
    public function __construct(
        private PlatformApi $platform,
        private AccessApi $access,
    ) {}

    public function notices(NoticesPage $page): NoticesPageData
    {
        $writers = $this->names(array_map(static fn (Notice $notice): string => $notice->createdBy, $page->notices));

        return new NoticesPageData(array_map(fn (Notice $notice): NoticeData => $this->written($notice, $writers), $page->notices), $page->next);
    }

    public function notice(Notice $notice): NoticeData
    {
        return $this->written($notice, $this->names([$notice->createdBy]));
    }

    public function staffMessages(StaffMessagesPage $page): StaffMessagesPageData
    {
        $senders = $this->names(array_map(static fn (StaffMessageRow $row): string => $row->senderId, $page->messages));
        $customers = [];

        foreach ($this->access->customers(array_values(array_unique(array_filter(array_map(static fn (StaffMessageRow $row): ?string => $row->customerId, $page->messages))))) as $customer) {
            $customers[strtolower($customer->id)] = trim($customer->firstName.' '.$customer->lastName);
        }

        return new StaffMessagesPageData(array_map(function (StaffMessageRow $row) use ($senders, $customers): StaffMessageData {
            $store = $this->platform->store(StoreId::fromString($row->storeId));

            return new StaffMessageData(
                $row->id,
                $store === null ? '' : $store->code,
                $row->audience,
                $row->customerId,
                $row->customerId === null ? null : ($customers[$row->customerId] ?? ''),
                $row->segmentId,
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
     */
    private function written(Notice $notice, array $writers): NoticeData
    {
        $store = $this->platform->store($notice->store);
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
            $writers[strtolower($notice->createdBy)] ?? '',
            $notice->startedAt === null ? null : self::utc($notice->startedAt),
            $notice->finishedAt === null ? null : self::utc($notice->finishedAt),
        );
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
