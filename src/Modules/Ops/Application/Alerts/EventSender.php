<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as Views;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\ListedPhoneCountries;
use Modules\Ops\Application\Messages\MarketingReach;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sends one queued email or SMS of an event's message, when its job runs (ops.md §1.1, §2.2): the person
 * read again - an anonymized customer, or a staff member no longer active, gets nothing - and the words
 * written again from the module's reads, in the person's language; nothing when there is nothing to tell
 * any more. Each send leaves one `ops.sent_messages` row (§5), never its text or address.
 *
 * Emails carry the store's logo and name (round 1 #23). The critical SMS goes through the provider of
 * the phone's country on the SMS countries list (access.md amendment 90), as the security codes do.
 */
final readonly class EventSender
{
    /** The cream tile at the top of every email (frontend.md §1.11), a public file of the site. */
    public const string LOGO = 'icons/email-logo.png';

    public function __construct(
        private AccessApi $access,
        private PlatformApi $platform,
        private MessageWriter $writer,
        private EmailSender $emails,
        private SmsSenders $sms,
        private ListedPhoneCountries $countries,
        private SentMessages $sent,
        private Views $views,
        private Config $config,
    ) {}

    /** @throws ProviderFailed for the queue to try again */
    public function emailCustomer(EventMessage $message, string $customerId): void
    {
        $customer = $this->access->customer($customerId);
        $store = $this->store($message);

        if ($customer === null || $store === null || ! MarketingReach::mayEmailService($customer)) {
            return;
        }

        $text = $this->writer->write($message, $store, $customer->locale, $customer->firstName);

        if ($text === null) {
            return;
        }

        $providerId = $this->emails->send($this->email($text, $store, $customer->locale, $customer->email, trim($customer->firstName.' '.$customer->lastName)));
        $this->record($message, $customer->id, null, MessageChannel::Email, $providerId, SendStatus::Sent);
    }

    /** @throws ProviderFailed for the queue to try again */
    public function emailStaff(EventMessage $message, string $staffId): void
    {
        $staff = $this->access->staff($staffId);
        $store = $this->store($message);

        if ($staff === null || $staff->status !== StaffStatus::Active || $store === null) {
            return;
        }

        $text = $this->writer->write($message, $store, $staff->locale, $staff->firstName);

        if ($text === null) {
            return;
        }

        $providerId = $this->emails->send($this->email($text, $store, $staff->locale, $staff->email, trim($staff->firstName.' '.$staff->lastName)));
        $this->record($message, null, $staff->id, MessageChannel::Email, $providerId, SendStatus::Sent);
    }

    /** @throws ProviderFailed for the queue to try again; a phone of no listed country too */
    public function smsStaff(EventMessage $message, string $staffId): void
    {
        $staff = $this->access->staff($staffId);
        $store = $this->store($message);

        if ($staff === null || $staff->status !== StaffStatus::Active || $staff->phone === null || $store === null || ! StaffAlerts::critical($message)) {
            return;
        }

        $text = $this->writer->write($message, $store, $staff->locale, $staff->firstName)?->sms;

        if ($text === null) {
            return;
        }

        $country = $this->countries->countryOf($staff->phone) ?? throw new ProviderFailed('No country on the SMS countries list serves this number.');
        $providerId = $this->sms->named($country->provider())->send($staff->phone, $text);
        $this->record($message, null, $staff->id, MessageChannel::Sms, $providerId, SendStatus::Sent);
    }

    /** The queue gave up on it (its last attempt failed): recorded as failed - never lost silently (§7). */
    public function failed(EventMessage $message, ?string $customerId, ?string $staffId, MessageChannel $channel): void
    {
        $known = $customerId !== null ? $this->access->customer($customerId) !== null : ($staffId === null || $this->access->staff($staffId) !== null);

        if ($known) {
            $this->record($message, $customerId, $staffId, $channel, null, SendStatus::Failed);
        }
    }

    private function email(MessageText $text, StoreDto $store, string $locale, string $to, string $toName): OutgoingEmail
    {
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $brand = $store->name->in($locale);
        $link = $text->link === null ? null : $this->root().$text->link;

        return new OutgoingEmail(
            (string) $this->config->get('mail.from.address'),
            $brand,
            $to,
            $toName === '' ? null : $toName,
            $text->subject,
            $this->views->make('ops::mail.event', [
                'lines' => $text->lines,
                'summary' => $text->summary,
                'action' => $text->action,
                'link' => $link,
                'locale' => $locale,
                'brand' => $brand,
                'logo' => $this->root().'/'.self::LOGO,
                'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            ])->render(),
            implode("\n\n", [
                ...$text->lines,
                ...($text->summary === [] ? [] : [implode("\n", array_map(static fn (array $row): string => "{$row['label']}: {$row['value']}", $text->summary))]),
                ...($link === null ? [] : ["{$text->action}: {$link}"]),
            ]),
        );
    }

    private function record(EventMessage $message, ?string $customerId, ?string $staffId, MessageChannel $channel, ?string $providerId, SendStatus $status): void
    {
        $this->sent->record(new SentMessage(
            strtolower((string) Str::ulid()),
            $message->kind,
            $customerId === null ? null : strtolower($customerId),
            $staffId === null ? null : strtolower($staffId),
            StoreId::fromString($message->storeId),
            $channel,
            $providerId,
            $status,
            CarbonImmutable::now()->toDateTimeImmutable(),
        ));
    }

    private function store(EventMessage $message): ?StoreDto
    {
        return $this->platform->store(StoreId::fromString($message->storeId));
    }

    /** The shop's own address - APP_URL, never the request's host. */
    private function root(): string
    {
        $root = $this->config->get('app.url');

        if (! is_string($root) || $root === '') {
            throw new LogicException('APP_URL must be set: every link an email carries is built on it.');
        }

        return rtrim($root, '/');
    }
}
