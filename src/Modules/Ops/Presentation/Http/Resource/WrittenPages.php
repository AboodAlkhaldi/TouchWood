<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Ops\Application\Query\FindCustomersToMessage\CustomerToMessage;
use Modules\Ops\Application\Query\ListNotices\NoticesPage;
use Modules\Ops\Application\Query\ListSubscribers\SubscribersPage;
use Modules\Ops\Domain\Model\BroadcastStatus;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Platform\Public\Contracts\PlatformApi;

/** Notices, the composer's customers and the subscribers as the screens get them (ops.md §4). */
final readonly class WrittenPages
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function notices(NoticesPage $page): NoticesPageData
    {
        return new NoticesPageData(array_map($this->notice(...), $page->notices), $page->next);
    }

    public function notice(Notice $notice): NoticeData
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
        );
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
