<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Messages;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as Views;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\ListedPhoneCountries;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\StoreContext;
use Shared\Domain\ValueObject\StoreId;

/**
 * C18, the security messages (ops.md §1.5 C18; access.md §2.3), in place of Access's temporary sender
 * (round 1 #26): codes by SMS, through the provider of the phone's country on the SMS countries list
 * (access.md amendment 87); links, and B2B's decisions about a company, by email through EmailSender.
 * The wording is the one Access had, unchanged, at `ops::security`.
 *
 * **Sent at once, never queued**: a code or a link never reaches a job payload, an event or a record.
 * No bell entry. Each send leaves one `ops.sent_messages` row - the kind, the person when the call
 * names one, the store, the channel, the provider's id, sent or failed - and never the code, the link,
 * the text or the address. A provider's failure is recorded, then thrown to the caller, which sends
 * after its commit and offers "send again".
 *
 * Branded with the store the message is about, or the store of the request, when there is one;
 * otherwise TouchWood's own sender (owner, 2026-10-28).
 */
final readonly class OpsSecurityMessages implements SecurityMessages
{
    /**
     * The cream tile at the top of every email (frontend.md §1.11), a public file of the site. Linked
     * at its address on APP_URL: EmailSender takes the body as HTML and text only, so the image cannot
     * travel inside the email as Laravel's mailer embedded it.
     */
    public const string LOGO = 'icons/email-logo.png';

    public function __construct(
        private EmailSender $emails,
        private SmsSenders $sms,
        private ListedPhoneCountries $countries,
        private SentMessages $sent,
        private Translator $translator,
        private Views $views,
        private PlatformApi $platform,
        private StoreContext $stores,
        private Config $config,
    ) {}

    public function emailVerification(CustomerDto $customer, string $link): void
    {
        $this->email('email_verification', ['name' => $customer->firstName, 'link' => $link], $customer->locale, $customer->email, $customer, null);
    }

    public function customerDeletionScheduled(CustomerDto $customer, DateTimeImmutable $on): void
    {
        $this->email('customer_deletion_scheduled', ['name' => $customer->firstName, 'date' => $on->format('Y-m-d')], $customer->locale, $customer->email, $customer, null);
    }

    public function staffInvitation(StaffDto $staff, string $link): void
    {
        $this->email('staff_invitation', ['name' => $staff->firstName, 'link' => $link], $staff->locale, $staff->email, null, $staff);
    }

    public function passwordReset(string $email, string $locale, string $link): void
    {
        $this->email('password_reset', ['link' => $link], $locale, $email, null, null);
    }

    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void
    {
        $this->email('staff_email_change', ['name' => $staff->firstName, 'link' => $link], $staff->locale, $newEmail, null, $staff);
    }

    public function phoneCode(string $phone, string $locale, string $code): void
    {
        $this->sms($phone, (string) $this->translator->get('ops::security.phone_code', ['code' => $code], $locale));
    }

    public function staffSignInCode(string $phone, string $locale, string $code): void
    {
        $this->sms($phone, (string) $this->translator->get('ops::security.sign_in_code', ['code' => $code], $locale));
    }

    public function companyApproved(CustomerDto $customer, ?string $note, ?string $storeId = null): void
    {
        $store = $this->storeOf($storeId);

        // The note is a line of its own, and only when staff wrote one: an empty "A note from our
        // team:" would read as something missing.
        $note = $note === null || trim($note) === '' ? [] : [
            (string) $this->translator->get('ops::security.company_approved.note', ['note' => $note], $customer->locale),
        ];

        $this->email(
            'company_approved',
            ['name' => $customer->firstName, 'link' => $this->shopFrontDoor()],
            $customer->locale,
            $customer->email,
            $customer,
            null,
            $store,
            [...$this->storeLine($customer, $store), ...$note],
        );
    }

    public function companyRejected(CustomerDto $customer, string $reason, ?string $storeId = null): void
    {
        $store = $this->storeOf($storeId);

        $this->email(
            'company_rejected',
            ['name' => $customer->firstName, 'reason' => $reason, 'link' => $this->shopFrontDoor()],
            $customer->locale,
            $customer->email,
            $customer,
            null,
            $store,
            $this->storeLine($customer, $store),
        );
    }

    public function companySuspended(CustomerDto $customer, string $reason, ?string $storeId = null): void
    {
        $store = $this->storeOf($storeId);

        $this->email(
            'company_suspended',
            ['name' => $customer->firstName, 'reason' => $reason, 'link' => $this->shopFrontDoor()],
            $customer->locale,
            $customer->email,
            $customer,
            null,
            $store,
            $this->storeLine($customer, $store),
        );
    }

    /**
     * One email, in the recipient's language: the message's subject and lines, any lines only this
     * send carries, and its one link with its button.
     *
     * @param  string  $message  the key under ops::security, e.g. "staff_invitation"
     * @param  array<string, string>  $values
     * @param  StoreDto|null  $store  the store the message is about; the request's when not given
     * @param  list<string>  $moreLines  already in the recipient's language
     *
     * @throws ProviderFailed
     */
    private function email(string $message, array $values, string $locale, string $to, ?CustomerDto $customer, ?StaffDto $staff, ?StoreDto $store = null, array $moreLines = []): void
    {
        $store ??= $this->requestStore();
        $link = $values['link'] ?? null;
        $action = $link === null ? null : (string) $this->translator->get("ops::security.{$message}.action", $values, $locale);
        $lines = [...(array) $this->translator->get("ops::security.{$message}.lines", $values, $locale), ...$moreLines];
        $lines = array_values(array_map(static fn (mixed $line): string => (string) $line, $lines));
        $brand = $store?->name->in($locale) ?? (string) $this->config->get('mail.from.name');

        $email = new OutgoingEmail(
            (string) $this->config->get('mail.from.address'),
            $brand,
            $to,
            $customer !== null ? trim($customer->firstName.' '.$customer->lastName) : ($staff !== null ? trim($staff->firstName.' '.$staff->lastName) : null),
            (string) $this->translator->get("ops::security.{$message}.subject", $values, $locale),
            $this->views->make('ops::mail.security', [
                'lines' => $lines,
                'action' => $action,
                'link' => $link,
                'locale' => $locale,
                'brand' => $brand,
                'logo' => $this->appUrl().'/'.self::LOGO,
                'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            ])->render(),
            implode("\n\n", [...$lines, ...($link === null ? [] : ["{$action}: {$link}"])]),
        );

        $this->record(MessageChannel::Email, $customer?->id, $staff?->id, $store, fn (): ?string => $this->emails->send($email));
    }

    /**
     * One SMS, through the provider of the number's country. Access refuses a number of a country not
     * on the list before it calls (amendment 87); one that reaches here anyway fails, and is recorded.
     *
     * @throws ProviderFailed
     */
    private function sms(string $phone, string $text): void
    {
        $this->record(MessageChannel::Sms, null, null, $this->requestStore(), function () use ($phone, $text): ?string {
            $country = $this->countries->countryOf($phone) ?? throw new ProviderFailed('No country on the SMS countries list serves this number.');

            return $this->sms->named($country->provider())->send($phone, $text);
        });
    }

    /**
     * The one row a send leaves (ops.md §5): sent with the provider's id, or failed - and then the
     * failure goes on to the caller.
     *
     * @param  Closure(): ?string  $send
     *
     * @throws ProviderFailed
     */
    private function record(MessageChannel $channel, ?string $customerId, ?string $staffId, ?StoreDto $store, Closure $send): void
    {
        try {
            $providerId = $send();
        } catch (ProviderFailed $failed) {
            $this->sent->record($this->row($channel, $customerId, $staffId, $store, null, SendStatus::Failed));

            throw $failed;
        }

        $this->sent->record($this->row($channel, $customerId, $staffId, $store, $providerId, SendStatus::Sent));
    }

    private function row(MessageChannel $channel, ?string $customerId, ?string $staffId, ?StoreDto $store, ?string $providerId, SendStatus $status): SentMessage
    {
        return new SentMessage(
            strtolower((string) Str::ulid()),
            MessageKind::Security,
            $customerId,
            $staffId,
            $store?->storeId(),
            $channel,
            $providerId,
            $status,
            CarbonImmutable::now()->toDateTimeImmutable(),
        );
    }

    /**
     * Which store's company the email is about, in the customer's language: an account may hold a
     * company in each store (b2b.md amendments 19(c), 20(i)). None when the store is not given or
     * not known.
     *
     * @return list<string>
     */
    private function storeLine(CustomerDto $customer, ?StoreDto $store): array
    {
        return $store === null ? [] : [
            (string) $this->translator->get('ops::security.company_store', ['store' => $store->name->in($customer->locale)], $customer->locale),
        ];
    }

    private function storeOf(?string $storeId): ?StoreDto
    {
        try {
            return $storeId === null ? null : $this->platform->store(StoreId::fromString($storeId));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** The store the request runs in, when it runs in one: a shop page's, never the panel's. */
    private function requestStore(): ?StoreDto
    {
        return $this->stores->has() ? $this->platform->store($this->stores->current()) : null;
    }

    /**
     * The shop's front door - APP_URL itself, with no store, language or page in it. The one link
     * B2B's decision emails carry, the same for every customer (owner, 2026-09-27): whoever opens it
     * lands where anybody typing the address would. Never the request's host (review of step 3b).
     */
    private function shopFrontDoor(): string
    {
        return $this->appUrl();
    }

    /** APP_URL without its trailing slash: every address an email carries is built on it. */
    private function appUrl(): string
    {
        $root = $this->config->get('app.url');

        if (! is_string($root) || $root === '') {
            throw new LogicException('APP_URL must be set: every customer link is built on it.');
        }

        return rtrim($root, '/');
    }
}
