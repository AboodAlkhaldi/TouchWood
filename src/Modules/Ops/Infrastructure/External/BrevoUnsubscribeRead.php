<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Newsletter\CustomerSubscriptions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Ops\Infrastructure\Eloquent\Moment;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The nightly read of Brevo's unsubscribes (ops.md §9.6 #1; owner, Content round 3 #17): an unsubscribe Brevo's
 * webhook lost, or never sent, is found the next night and taken as the webhook takes one.
 *
 * **Who counts as unsubscribed** (the owner's answer (b), 2026-11-10): a contact whose `emailBlacklisted` is true,
 * or whose `listUnsubscribed` holds this list's id. Another list's id alone leaves them. Each such contact goes to
 * `CustomerSubscriptions::unsubscribedAtProvider`, the webhook's own path: a guest's row removed, a customer's consent
 * withdrawn through Access, every row of the email, and the webhook's replay rule, with the contact's `modifiedAt`
 * as the event's time (a row that joined after it stays; no readable time counts, as a webhook without `ts_event`).
 *
 * As Brevo documents it, read 2026-11-10 (https://developers.brevo.com/reference/get-contacts-from-list):
 * - `GET https://api.brevo.com/v3/contacts/lists/{listId}/contacts`, the key in the `api-key` header.
 * - `modifiedSince`: "Filter (urlencoded) the contacts modified after a given UTC date-time
 *   (YYYY-MM-DDTHH:mm:ss.SSSZ)"; `limit`: "Number of documents per page", 50 by default, "maximum 500"; `offset`:
 *   "Index of the first document of the page"; `sort`: "in the ascending/descending order of record creation",
 *   `desc` by default. Ascending here, so a contact made during the walk lands on the last page, not before it.
 * - The answer: `contacts`, `count`; each contact `email`, `emailBlacklisted` ("Blacklist status for email
 *   campaigns"), `modifiedAt` ("Last modification UTC date-time of the contact"), `listUnsubscribed` (a list of
 *   ids, undescribed). 404: the list is not found.
 *
 * **Not checked against the real account** (docs/HANDOFF.md §15.4, which is why `BREVO_NIGHTLY_READ` starts off):
 * whether a list-only unsubscribe moves `modifiedAt`, whether such a contact is still returned by its list, and
 * whether adding a contact back to a list clears `listUnsubscribed` - if it does not, someone who left and agreed
 * again would be withdrawn on the next night.
 *
 * Each list is read from an hour before its last full read began (the overlap covers a read's own length and a
 * clock's drift); the first time, from an hour before the list was made, so nothing on it is missed. Pages of 500,
 * at most MOST_PAGES: a list read to its end keeps the read's start; one that reaches the limit keeps the old start,
 * says so in the log, and is read again from there the next night. The unsubscribes are taken once the pages are
 * read, never between two of them. A failing page throws ProviderFailed before anything is taken, for the queue to
 * try the whole list again by ProviderFailureRules; taken twice, an unsubscribe changes nothing.
 *
 * The key is the server's, a `SensitiveParameter`; nothing a contact holds is logged.
 */
final readonly class BrevoUnsubscribeRead
{
    public const int PAGE = 500;

    /**
     * 7,500 contacts changed on one list in a night (the builder's pick). It bounds a read's time too: each page waits
     * at most BrevoAnswers::CONNECT_SECONDS and SECONDS (13 s), so the walk's calls stay under 195 s, inside the job's
     * timeout (ReadBrevoListUnsubscribesJob).
     */
    public const int MOST_PAGES = 15;

    public const int OVERLAP_SECONDS = 3600;

    public function __construct(
        private Factory $http,
        private ConnectionInterface $db,
        private CustomerSubscriptions $subscriptions,
        private LoggerInterface $log,
        #[\SensitiveParameter] private string $apiKey,
    ) {}

    /** @return list<int> the Brevo ids of every list kept, in order */
    public function lists(): array
    {
        return array_values(array_map(intval(...), $this->db->table('ops.newsletter_lists')->orderBy('brevo_list_id')->pluck('brevo_list_id')->all()));
    }

    /**
     * Reads one list's contacts changed since its last full read, and takes each unsubscribe; `$runStart` is kept
     * when the list was read to its end.
     *
     * @throws ProviderFailed
     */
    public function read(int $listId, DateTimeImmutable $runStart): void
    {
        $row = $this->db->table('ops.newsletter_lists')->where('brevo_list_id', $listId)->first(['created_at', 'unsubscribes_read_at']);

        // Forgotten since it was queued: its list is gone at Brevo.
        if ($row === null) {
            return;
        }

        $from = CarbonImmutable::parse((string) ($row->unsubscribes_read_at ?? $row->created_at))->subSeconds(self::OVERLAP_SECONDS);
        $left = [];

        for ($page = 0; $page < self::MOST_PAGES; $page++) {
            $contacts = $this->page($listId, $from->toDateTimeImmutable(), $page * self::PAGE);

            // The list is gone at Brevo: nothing to read. Adding a contact forgets it (BrevoNewsletterList).
            if ($contacts === null) {
                return;
            }

            foreach ($contacts as $contact) {
                $unsubscribe = self::unsubscribe($listId, $contact);

                if ($unsubscribe !== null) {
                    $left[] = $unsubscribe;
                }
            }

            if (count($contacts) < self::PAGE) {
                // Taken once every page is read: a guest's unsubscribe takes the contact off this list (a queued
                // RemoveNewsletterContactJob), and done mid-walk it would move the next page's first contact back
                // onto a page already read, which would then be missed (the builder's pick).
                foreach ($left as [$email, $at]) {
                    $this->subscriptions->unsubscribedAtProvider($email, $at);
                }

                $this->db->update('UPDATE ops.newsletter_lists SET unsubscribes_read_at = GREATEST(unsubscribes_read_at, ?::timestamptz) WHERE brevo_list_id = ?', [Moment::of($runStart), $listId]);

                return;
            }
        }

        // At the limit, what the pages held is taken too: no page is read after it, so none can be moved under the walk.
        foreach ($left as [$email, $at]) {
            $this->subscriptions->unsubscribedAtProvider($email, $at);
        }

        $this->log->error('Ops: the nightly read of Brevo\'s unsubscribes stopped at its limit of '.self::MOST_PAGES.' pages of '.self::PAGE.' on Brevo list '.$listId.': the rest was not read, and the next night starts from the same moment. Raise BrevoUnsubscribeRead::MOST_PAGES if the list is that busy.');
    }

    /**
     * One page of the list's contacts changed since `$from`; null when the list is not found.
     *
     * @return list<array<array-key, mixed>>|null
     *
     * @throws ProviderFailed
     */
    private function page(int $listId, DateTimeImmutable $from, int $offset): ?array
    {
        $key = BrevoAnswers::key($this->apiKey);

        try {
            $response = $this->http->withHeaders(['api-key' => $key])->acceptJson()
                ->connectTimeout(BrevoAnswers::CONNECT_SECONDS)->timeout(BrevoAnswers::SECONDS)
                ->get(BrevoNewsletterList::BASE."/contacts/lists/{$listId}/contacts", [
                    'modifiedSince' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
                    'limit' => self::PAGE,
                    'offset' => $offset,
                    'sort' => 'asc',
                ]);
        } catch (ConnectionException) {
            throw new ProviderFailed('Brevo: the API could not be reached.');
        }

        if (BrevoAnswers::alreadyGone($response)) {
            return null;
        }

        if (! BrevoAnswers::isBrevos($response)) {
            throw BrevoAnswers::refused('the list\'s contacts', $response);
        }

        return self::contacts($response);
    }

    /**
     * The page's contacts, each an object. No `contacts` with a count of 0 is an empty page; anything else that is
     * not a list of objects is not Brevo's answer, and may pass.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ProviderFailed
     */
    private static function contacts(Response $response): array
    {
        $contacts = $response->json('contacts');

        if ($contacts === null && $response->json('count') === 0) {
            return [];
        }

        if (! is_array($contacts) || ! array_is_list($contacts) || array_filter($contacts, is_array(...)) !== $contacts) {
            throw new ProviderFailed('Brevo answered the list\'s contacts with what it does not read: HTTP '.$response->status().'.');
        }

        return $contacts;
    }

    /**
     * The contact's email and the event's time when it counts as unsubscribed from this list; null when it does not,
     * or holds no email the lists keep.
     *
     * @param  array<array-key, mixed>  $contact
     * @return array{0: string, 1: ?DateTimeImmutable}|null
     */
    private static function unsubscribe(int $listId, array $contact): ?array
    {
        $left = is_array($contact['listUnsubscribed'] ?? null) && in_array($listId, $contact['listUnsubscribed'], true);

        if (($contact['emailBlacklisted'] ?? null) !== true && ! $left) {
            return null;
        }

        try {
            $email = Subscriber::email(is_string($contact['email'] ?? null) ? $contact['email'] : '');
        } catch (InvalidOpsInput) {
            return null;
        }

        return [$email, self::moment($contact['modifiedAt'] ?? null)];
    }

    /** Brevo's UTC time, or none when it cannot be read: the unsubscribe then counts, as a webhook's without a time. */
    private static function moment(mixed $at): ?DateTimeImmutable
    {
        if (! is_string($at) || $at === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($at, 'UTC')->toDateTimeImmutable();
        } catch (Throwable) {
            return null;
        }
    }
}
