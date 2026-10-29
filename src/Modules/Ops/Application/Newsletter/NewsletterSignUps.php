<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Newsletter;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\WrittenEmails;
use Modules\Ops\Domain\Exception\ConfirmationLinkInvalid;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
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
 *   tells who subscribed. A new or still-unconfirmed sign-up gets a new link; the old one stops
 *   working. The token is kept only as its hash, and the email carrying it is **sent at once after the
 *   commit, never queued** (conventions: a link that proves who someone is never reaches a job).
 * - **Confirming** puts the guest on the store's list and queues the copy to the provider. An unknown,
 *   used or week-old link confirms nothing.
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
    ) {}

    /**
     * The shop's box: a guest gives an email to the store's list, in the page's language.
     *
     * @throws InvalidOpsInput for what is not an email address
     * @throws ProviderFailed when the confirmation could not be sent - the person may try again
     */
    public function subscribe(StoreDto $store, string $locale, string $email): void
    {
        $email = Subscriber::email($email);
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $token = Str::random(48);
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        $send = $this->db->transaction(function () use ($store, $locale, $email, $token, $now): bool {
            $existing = $this->subscribers->lockByEmail($store->storeId(), $email);

            // Already on the list - a confirmed guest, or a customer who agreed: nothing to send.
            if ($existing !== null && $existing->isConfirmed()) {
                return false;
            }

            $this->subscribers->save(new Subscriber(
                $existing->id ?? strtolower((string) Str::ulid()),
                $store->storeId(),
                $email,
                $locale,
                null,
                self::hash($token),
                $now,
                null,
            ));

            return true;
        });

        if ($send) {
            $this->sendConfirmation($store, $locale, $email, $token);
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

    /** A marketing email's unsubscribe link, its signature already checked by the route. */
    public function unsubscribeCustomer(string $customerId): void
    {
        $this->queue->withdrawConsent(strtolower($customerId), MarketingConsentSource::UnsubscribeLink);
    }

    /** @throws ProviderFailed */
    private function sendConfirmation(StoreDto $store, string $locale, string $email, string $token): void
    {
        $root = $this->config->get('app.url');

        if (! is_string($root) || $root === '') {
            throw new LogicException('APP_URL must be set: every customer link is built on it.');
        }

        // On the shop's own address, never the request's host.
        $link = rtrim($root, '/').$this->urls->route('storefront.ops.newsletter.confirm', ['store' => $store->code, 'locale' => $locale, 'token' => $token], false);
        $status = SendStatus::Sent;
        $providerId = null;

        try {
            $providerId = $this->emails->send($this->written->newsletterConfirmation($email, $locale, $store, $link));
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
