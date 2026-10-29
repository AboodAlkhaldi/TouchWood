<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Infrastructure\Eloquent\Moment;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * The newsletter's lists in Brevo (ops.md §1.9, §2.2): one list per store and language, made the first
 * time a contact needs it, in the folder the server names, and its id kept in `ops.newsletter_lists`.
 *
 * Every request is the one Brevo's reference shows, read 2026-10-29, with the key in the `api-key`
 * header:
 * - Create a list - https://developers.brevo.com/reference/create-list.md
 *   POST https://api.brevo.com/v3/contacts/lists {name, folderId} (both required); 201 carries `id`.
 * - Create a contact - https://developers.brevo.com/reference/createcontact
 *   POST https://api.brevo.com/v3/contacts {email, listIds, updateEnabled}; `updateEnabled` updates a
 *   contact that exists in the same request. The reference documents only 201; the answer to an update
 *   is not documented, so any 2xx is taken as done.
 * - Remove contacts from a list - https://developers.brevo.com/reference/removecontactfromlist
 *   POST https://api.brevo.com/v3/contacts/lists/{listId}/contacts/remove {emails}; 201; 404 when the
 *   list is not found - nothing is left to take out.
 * - Update a contact - https://developers.brevo.com/reference/updatecontact (read 2026-10-30)
 *   PUT https://api.brevo.com/v3/contacts/{identifier}, the email urlencoded in the path;
 *   `emailBlacklisted` "set/unset this field to blacklist/allow the contact for emails" - false lets
 *   it receive email again; 204; 404 when there is no such contact.
 * - Delete a contact - https://developers.brevo.com/reference/deletecontact
 *   DELETE https://api.brevo.com/v3/contacts/{identifier}, an email or an id in the path; 204; 404
 *   when there is no such contact - already gone.
 *
 * The key is the server's (`BREVO_API_KEY`), never a setting or a table. What is sent is never logged
 * here, and a refusal's message names only Brevo's status and error code, never the email.
 */
final readonly class BrevoNewsletterList implements NewsletterList
{
    public const string BASE = 'https://api.brevo.com/v3';

    public function __construct(
        private Factory $http,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private string $apiKey,
        private ?int $folderId,
    ) {}

    public function add(string $email, StoreId $store, string $locale): void
    {
        $listId = $this->listId($store, $locale, create: true);

        $response = $this->call(fn (PendingRequest $brevo): Response => $brevo->post(self::BASE.'/contacts', [
            'email' => $email,
            'listIds' => [$listId],
            'updateEnabled' => true,
        ]));

        if (! $response->successful()) {
            throw self::refused('the contact', $response);
        }
    }

    public function remove(string $email, StoreId $store, string $locale): void
    {
        $listId = $this->listId($store, $locale, create: false);

        // No list was ever made for it: nobody is on it.
        if ($listId === null) {
            return;
        }

        $response = $this->call(fn (PendingRequest $brevo): Response => $brevo->post(self::BASE."/contacts/lists/{$listId}/contacts/remove", [
            'emails' => [$email],
        ]));

        if (! $response->successful() && $response->status() !== 404) {
            throw self::refused('the removal from a list', $response);
        }
    }

    public function allow(string $email): void
    {
        $response = $this->call(fn (PendingRequest $brevo): Response => $brevo->put(self::BASE.'/contacts/'.rawurlencode($email), [
            'emailBlacklisted' => false,
        ]));

        if (! $response->successful() && $response->status() !== 404) {
            throw self::refused('the contact\'s update', $response);
        }
    }

    public function forget(string $email): void
    {
        $response = $this->call(fn (PendingRequest $brevo): Response => $brevo->delete(self::BASE.'/contacts/'.rawurlencode($email)));

        if (! $response->successful() && $response->status() !== 404) {
            throw self::refused('the deletion of a contact', $response);
        }
    }

    /**
     * The Brevo list of the store and language: the one kept, or - when asked to - a new one, made
     * under a lock so two workers never make two.
     *
     * @return ($create is true ? int : int|null)
     *
     * @throws ProviderFailed
     */
    private function listId(StoreId $store, string $locale, bool $create): ?int
    {
        $kept = $this->kept($store, $locale);

        if ($kept !== null || ! $create) {
            return $kept;
        }

        if ($this->folderId === null || $this->folderId < 1) {
            throw new ProviderFailed('Brevo: no folder is set for the newsletter lists (BREVO_NEWSLETTER_FOLDER_ID).');
        }

        return $this->db->transaction(function () use ($store, $locale): int {
            $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["ops:newsletter-list:{$store->value}:{$locale}"], false);
            $kept = $this->kept($store, $locale);

            if ($kept !== null) {
                return $kept;
            }

            $code = $this->platform->store($store)->code ?? $store->value;
            $response = $this->call(fn (PendingRequest $brevo): Response => $brevo->post(self::BASE.'/contacts/lists', [
                'name' => "TouchWood {$code} {$locale}",
                'folderId' => $this->folderId,
            ]));
            $id = $response->json('id');

            if ($response->status() !== 201 || ! is_int($id) || $id < 1) {
                throw self::refused('the new list', $response);
            }

            $this->db->table('ops.newsletter_lists')->insert([
                'store_id' => $store->value,
                'locale' => $locale,
                'brevo_list_id' => $id,
                'created_at' => Moment::of(CarbonImmutable::now()->toDateTimeImmutable()),
            ]);

            return $id;
        });
    }

    private function kept(StoreId $store, string $locale): ?int
    {
        $id = $this->db->table('ops.newsletter_lists')->where('store_id', $store->value)->where('locale', $locale)->value('brevo_list_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws ProviderFailed
     */
    private function call(callable $request): Response
    {
        if (trim($this->apiKey) === '') {
            throw new ProviderFailed('Brevo: no API key is set (BREVO_API_KEY).');
        }

        try {
            return $request($this->http->withHeaders(['api-key' => $this->apiKey])->acceptJson()->asJson()->connectTimeout(5)->timeout(15));
        } catch (ConnectionException) {
            throw new ProviderFailed('Brevo: the API could not be reached.');
        }
    }

    private static function refused(string $what, Response $response): ProviderFailed
    {
        $code = $response->json('code');

        return new ProviderFailed("Brevo refused {$what}: HTTP ".$response->status().(is_string($code) ? " ({$code})" : '').'.');
    }
}
