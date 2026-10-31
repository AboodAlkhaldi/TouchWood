<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as Views;
use LogicException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\ListedPhoneCountries;
use Modules\Ops\Application\Messages\MarketingReach;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sends one queued email or SMS of an event's message, when its job runs (ops.md §1.1, §2.2). Its
 * `ops.sent_messages` row was written QUEUED with the listener's work (§5); here, outside any
 * transaction, the person is read again - an anonymized customer, or a staff member no longer active,
 * gets nothing - and the words are written again from the module's reads, in the person's language;
 * then the provider is called and the row marked SENT. A row already SENT, or gone, sends nothing; a
 * row with nothing left to send is removed, so only what was sent is recorded. **At least once**: a
 * provider that took a message just before its row could be marked may get it again on the retry.
 *
 * Emails carry the store's logo and name (round 1 #23). The critical SMS goes only to an active super
 * admin whose critical-SMS switch is on **when it leaves** (access.md amendment 81), through the
 * provider of the phone's country on the SMS countries list (amendment 90), as the security codes do.
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
    public function emailCustomer(EventMessage $message, string $customerId, string $sentId): void
    {
        if (! $this->due($sentId)) {
            return;
        }

        $customer = $this->access->customer($customerId);
        $store = $this->store($message);
        $text = $customer === null || $store === null || ! MarketingReach::mayEmailService($customer)
            ? null
            : $this->writer->write($message, $store, $customer->locale, $customer->firstName, $customer->id);

        if ($customer === null || $store === null || $text === null) {
            $this->sent->forget($sentId);

            return;
        }

        $providerId = $this->emails->send($this->email($text, $store, $customer->locale, $customer->email, trim($customer->firstName.' '.$customer->lastName)));
        $this->sent->markSent($sentId, $providerId);
    }

    /** @throws ProviderFailed for the queue to try again */
    public function emailStaff(EventMessage $message, string $staffId, string $sentId): void
    {
        if (! $this->due($sentId)) {
            return;
        }

        $staff = $this->access->staff($staffId);
        $store = $this->store($message);
        $text = $staff === null || $staff->status !== StaffStatus::Active || $store === null
            ? null
            : $this->writer->write($message, $store, $staff->locale, $staff->firstName);

        if ($staff === null || $store === null || $text === null) {
            $this->sent->forget($sentId);

            return;
        }

        $providerId = $this->emails->send($this->email($text, $store, $staff->locale, $staff->email, trim($staff->firstName.' '.$staff->lastName)));
        $this->sent->markSent($sentId, $providerId);
    }

    /** @throws ProviderFailed for the queue to try again; a phone of no listed country too */
    public function smsStaff(EventMessage $message, string $staffId, StaffNotificationTopic $topic, string $permission, string $sentId): void
    {
        if (! $this->due($sentId)) {
            return;
        }

        $staff = $this->access->staff($staffId);
        $store = $this->store($message);
        $phone = $staff?->phone;
        $allowed = $staff !== null && $store !== null && $phone !== null && $staff->status === StaffStatus::Active && $staff->isSuperAdmin
            && StaffAlerts::critical($message) && $this->switchedOn($store, $topic, $permission, $staff->id);
        $text = $allowed ? $this->writer->write($message, $store, $staff->locale, $staff->firstName)?->sms : null;

        if ($phone === null || $text === null) {
            $this->sent->forget($sentId);

            return;
        }

        $country = $this->countries->countryOf($phone) ?? throw new ProviderFailed('No country on the SMS countries list serves this number.');
        $providerId = $this->sms->named($country->provider())->send($phone, $text);
        $this->sent->markSent($sentId, $providerId);
    }

    /** The queue gave up on it (its last attempt failed): marked failed - never lost silently (§7). */
    public function failed(string $sentId): void
    {
        $this->sent->markFailed($sentId);
    }

    /** Whether the row is still waiting to go: not sent yet, and not removed with its customer (§6). */
    private function due(string $sentId): bool
    {
        $status = $this->sent->status($sentId);

        return $status !== null && $status !== SendStatus::Sent;
    }

    /** The person's critical-SMS switch now, as the alert's own read gives it (access.md amendments 80, 81). */
    private function switchedOn(StoreDto $store, StaffNotificationTopic $topic, string $permission, string $staffId): bool
    {
        foreach ($this->access->staffToAlert($store->storeId(), $topic, $permission) as $recipient) {
            if (strtolower($recipient->staffId) === strtolower($staffId)) {
                return $recipient->criticalSms;
            }
        }

        return false;
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
