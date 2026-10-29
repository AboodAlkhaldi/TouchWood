<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Newsletter;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\WrittenEmails;
use Modules\Ops\Domain\Exception\ConfirmationLinkInvalid;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SubscribeInYourAccount;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Domain\Repository\Subscribers;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The shop's newsletter box and its links (ops.md §1.9, §3: "guest / signed link"). Open to whoever
 * uses them, as Sync's provider notices are: the box is guarded by the controller's rate limit and the
 * **double opt-in** - nothing reaches a list until the email's owner follows the link sent to it - and
 * each link by what it carries (the token; the URL's signature).
 *
 * - **Signing up** gives the same answer whether the email was on the list or not, so the box never
 *   tells who subscribed. An email that belongs to a customer account is refused instead: its owner
 *   ticks "Send me offers and news" in the account (§1.3, §5; AccessApi::isCustomerEmail). A new or still-unconfirmed sign-up gets a new link; the old one stops
 *   working. The token is kept only as its hash, and the email carrying it is **sent at once after the
 *   commit, never queued** (conventions: a link that proves who someone is never reaches a job).
 * - **Confirming** puts the guest on the store's list and queues the copy to the provider. An unknown,
 *   used or week-old link confirms nothing.
 * - **A guest's unsubscribe link** - signed, carried by the confirmation email, valid as long as the
 *   sign-up is on the list - takes the guest off at once, and the provider's list follows (§3).
 * - **A marketing email's unsubscribe link** withdraws the customer's consent at once, through Access,
 *   as the system - on the queue, because only the system may (access.md amendment 79).
 */
final readonly class NewsletterSignUps
{
    /** An unconfirmed sign-up is kept, and its link works, seven days (owner, 2026-10-28). */
    public const int DAYS = 7;

    public function __construct(
        private Subscribers $subscribers,
        private SentMessages $sent,
        private NewsletterQueue $queue,
        private EmailSender $emails,
        private WrittenEmails $written,
        private ConnectionInterface $db,
        private UrlGenerator $urls,
        private Config $config,
        private AccessApi $access,
    ) {}

    /**
     * The shop's box: a guest gives an email to the store's list, in the page's language.
     *
     * @throws InvalidOpsInput for what is not an email address
     * @throws SubscribeInYourAccount for a customer's email
     * @throws ProviderFailed when the confirmation could not be sent - the person may try again
     */
    public function subscribe(StoreDto $store, string $locale, string $email): void
    {
        $email = Subscriber::email($email);

        if ($this->access->isCustomerEmail($email)) {
            throw new SubscribeInYourAccount;
        }

        $locale = $locale === 'ar' ? 'ar' : 'en';
        $token = Str::random(48);
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        // Two sign-ups of a new email at once both find no row; the second insert meets the unique
        // index - it is run again, finds the first one's row, and goes on as a second sign-up would.
        $signUp = fn (): ?string => $this->db->transaction(function () use ($store, $locale, $email, $token, $now): ?string {
            $existing = $this->subscribers->lockByEmail($store->storeId(), $email);

            // Already on the list - a confirmed guest, or a customer who agreed: nothing to send.
            if ($existing !== null && $existing->isConfirmed()) {
                return null;
            }

            $id = $existing->id ?? strtolower((string) Str::ulid());
            $this->subscribers->save(new Subscriber(
                $id,
                $store->storeId(),
                $email,
                $locale,
                null,
                self::hash($token),
                $now,
                null,
            ));

            return $id;
        });

        try {
            $sendTo = $signUp();
        } catch (UniqueConstraintViolationException) {
            $sendTo = $signUp();
        }

        if ($sendTo !== null) {
            $this->sendConfirmation($store, $locale, $email, $token, $sendTo);
        }
    }

    /** @throws ConfirmationLinkInvalid */
    public function confirm(string $token): void
    {
        if ($token === '' || strlen($token) > 128) {
            throw new ConfirmationLinkInvalid;
        }

        $this->db->transaction(function () use ($token): void {
            $subscriber = $this->subscribers->lockByTokenHash(self::hash($token));
            $now = CarbonImmutable::now();

            if ($subscriber === null || $subscriber->createdAt < $now->subDays(self::DAYS)->toDateTimeImmutable()) {
                throw new ConfirmationLinkInvalid;
            }

            $this->subscribers->save(new Subscriber(
                $subscriber->id,
                $subscriber->store,
                $subscriber->email,
                $subscriber->locale,
                null,
                null,
                $subscriber->createdAt,
                $now->toDateTimeImmutable(),
                $subscriber->brevoEmail,
                $subscriber->brevoLocale,
            ));
            $this->queue->sync($subscriber->id);
        });
    }

    /**
     * A guest's unsubscribe link, its signature already checked by the route: the sign-up goes, and
     * the provider's list follows. One gone already, or a customer's row, changes nothing.
     */
    public function unsubscribeGuest(string $subscriberId): void
    {
        $this->db->transaction(function () use ($subscriberId): void {
            $subscriber = $this->subscribers->lock($subscriberId);

            if ($subscriber === null || ! $subscriber->isGuest()) {
                return;
            }

            $this->subscribers->delete($subscriber->id);

            if ($subscriber->brevoEmail !== null && $subscriber->brevoLocale !== null) {
                $this->queue->remove($subscriber->brevoEmail, $subscriber->store, $subscriber->brevoLocale);
            }
        });
    }

    /** A marketing email's unsubscribe link, its signature already checked by the route. */
    public function unsubscribeCustomer(string $customerId): void
    {
        $this->queue->withdrawConsent(strtolower($customerId), MarketingConsentSource::UnsubscribeLink);
    }

    /** @throws ProviderFailed */
    private function sendConfirmation(StoreDto $store, string $locale, string $email, string $token, string $subscriberId): void
    {
        $root = $this->config->get('app.url');

        if (! is_string($root) || $root === '') {
            throw new LogicException('APP_URL must be set: every customer link is built on it.');
        }

        // On the shop's own address, never the request's host.
        $root = rtrim($root, '/');
        $link = $root.$this->urls->route('storefront.ops.newsletter.confirm', ['store' => $store->code, 'locale' => $locale, 'token' => $token], false);
        // Signed on its path alone (`signed:relative`), so the shop's own address carries it.
        $leave = $root.$this->urls->signedRoute('storefront.ops.newsletter.leave', ['store' => $store->code, 'locale' => $locale, 'subscriber' => $subscriberId], null, false);
        $status = SendStatus::Sent;
        $providerId = null;

        try {
            $providerId = $this->emails->send($this->written->newsletterConfirmation($email, $locale, $store, $link, $leave));
        } catch (ProviderFailed $failed) {
            $status = SendStatus::Failed;

            throw $failed;
        } finally {
            // Never the email or the link: the kind, the store, the channel, the outcome.
            $this->sent->record(new SentMessage(
                strtolower((string) Str::ulid()),
                MessageKind::NewsletterConfirmation,
                null,
                null,
                $store->storeId(),
                MessageChannel::Email,
                $providerId,
                $status,
                CarbonImmutable::now()->toDateTimeImmutable(),
            ));
        }
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
