<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Provider;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use JsonException;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Provider\ProviderAdapter;
use Modules\Sync\Domain\ValueObject\ProviderReason;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * **Odoo, read through its own JSON-2 API** (sync.md §2.4, owner 2026-11-09): `POST
 * {account}/json/2/product.product/read` with `{"ids": [...], "fields": ["list_price", "free_qty"]}`, signed
 * in with `Authorization: Bearer <token>` - the store's key. Our codes ARE Odoo's `product.product` ids, so a
 * read asks by our own codes, 500 a call, and gets a bare JSON list back; an id Odoo does not have is simply
 * left out of it. Odoo's names (`id`, `list_price`, `free_qty`) stay here: the rest of Sync says code, price
 * and stock.
 *
 * - **Pace**: one call at a time - the read waits for each answer - and at least 1 second between the end
 *   of a store's call and its next one (§9.1 #8). The adapter is one per worker (bound once), so a store's
 *   next read in the same worker keeps the pace too. The waits go through Laravel's `Sleep`, which a test
 *   fakes.
 * - **Errors**: a `429` waits `Retry-After` (60 seconds at most) and asks once more; anything else fails
 *   with a short reason - what failed and its status code, kept as a code and its values (`ProviderReason`)
 *   and written in the reader's language - never the provider's body, the address or the token (§2.1). The
 *   read's retries are `ReadStoreJob`'s (§1.7).
 * - **The test** is the one call made in a web request: 10 seconds at most (§9.3 #21), so a `429` there
 *   fails at once rather than wait.
 * - **One id Odoo refuses** (a `403` or another `4xx` but `401` and `429`) does not fail the read: the call is
 *   cut in halves until the ids refused are found, each given back as refused - a bad value for its size - and
 *   the rest read on. Past `MOST_REFUSED` ids, or every id of a read of more than one, it is the token, not
 *   an id: the read fails with Odoo's reason.
 * - **An answer past 5 MB** is not read on: its `Content-Length`, then what has come, stop it.
 * - **Each read logs one line**: how many ids, calls and seconds - never a code or the token.
 */
final class OdooAdapter implements ProviderAdapter
{
    /** Odoo's standard JSON-2 path for reading variants, after the account's address (§2.4). */
    public const string PATH = '/json/2/product.product/read';

    /** The two fields read, in Odoo's names: the price shown without VAT, and the stock free to order (§2.4). */
    public const array FIELDS = ['list_price', 'free_qty'];

    /**
     * Ids a call (§2.4). 678 in one call worked on the owner's account (2026-11-10); 500 keeps each body
     * small (about 4 KB) and well inside it - the build's pick, lowered if Odoo's team names a smaller limit.
     */
    public const int MOST_IDS = 500;

    /** The test's one id (§2.4): a `200` proves the address and the token, whether or not it exists. */
    public const int TEST_ID = 1;

    /** Between a store's calls, at least (§9.1 #8). */
    public const int GAP_MILLISECONDS = 1000;

    /** A read's call waits this long at most (§2.4). */
    public const int READ_SECONDS = 30;

    /** The Super Admin's test waits this long at most: it runs in a web request (§9.3 #21). */
    public const int TEST_SECONDS = 10;

    /** Reaching Odoo - before any answer - takes this long at most, in a read and the test alike. */
    public const int CONNECT_SECONDS = 10;

    /** A `429`'s `Retry-After` is waited this long at most (§2.4). */
    public const int MOST_RETRY_AFTER = 60;

    /**
     * A code that can be an Odoo id: digits with no leading zero, up to `MOST_ID`. Odoo never writes an id
     * another way, so any other code is not asked (§2.4) - the night's read reports it as not at the provider
     * when the size is switched on (§1.6).
     */
    private const string ODOO_ID = '/\A[1-9][0-9]{0,9}\z/';

    /** Odoo's ids are PostgreSQL `integer`s (int4): none is larger, so a larger code is never sent (§2.4). */
    public const int MOST_ID = 2147483647;

    /** Ids one read may find refused by Odoo before it is taken for the token's refusal, failing the read. */
    public const int MOST_REFUSED = 10;

    /** An answer is read this far at most (§2.4): about 100 times what 500 ids take. */
    public const int MOST_ANSWER_BYTES = 5 * 1024 * 1024;

    /** @var array<string, float> per store, when its last call ended - milliseconds */
    private array $lastCallEnded = [];

    /** Calls made since the read began, for its one log line. */
    private int $calls = 0;

    /** Set by the HTTP client's callbacks when they stop an answer past `MOST_ANSWER_BYTES`. */
    private bool $tooLarge = false;

    public function __construct(
        private readonly Factory $http,
        private readonly LoggerInterface $log,
    ) {}

    /** Odoo asks for nothing beside the address, the key and the secret (§2.4). */
    public function fields(): array
    {
        return [];
    }

    /** Odoo's own API, built against (Q38). */
    public function ready(): bool
    {
        return true;
    }

    /**
     * The account's address alone - `https://<account>.odoo.com` (§2.4): no path, query or fragment; a closing
     * slash dropped. The path is Odoo's own and added here. The shared rule has checked HTTPS and the host.
     */
    public function address(string $address): string
    {
        if (strpbrk($address, '?#') !== false) {
            throw new InvalidSyncInput('address', rule: InvalidSyncInput::ADDRESS_QUERY);
        }

        $address = rtrim($address, '/');
        $parts = parse_url($address);

        // Not one the HTTP client can call (a bad port, a "\" in its host): said as such, not as its path.
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new InvalidSyncInput('address', rule: InvalidSyncInput::ADDRESS_MALFORMED);
        }

        if (($parts['path'] ?? '') !== '') {
            throw new InvalidSyncInput('address', rule: InvalidSyncInput::ADDRESS_PATH);
        }

        return $address;
    }

    /** One call naming id 1, the answer a list (§2.4). */
    public function test(Connection $connection): void
    {
        $this->post($connection, [self::TEST_ID], self::TEST_SECONDS, waitWhenTooMany: false);
    }

    /**
     * Our codes that can be Odoo ids, each once, 500 a call; one value for each id Odoo answers, under our code,
     * and one marked refused for each id Odoo refuses. An id Odoo does not have is left out of its answer, and
     * so of this (§2.4).
     */
    public function read(Connection $connection, array $codes): iterable
    {
        return $this->asked($connection, $codes);
    }

    /**
     * @param  list<string>  $codes
     * @return Generator<int, ProviderValue>
     */
    private function asked(Connection $connection, array $codes): Generator
    {
        // Trimmed (§1.1 rule 5), then by the id each one is: digits with no leading zero are one id each.
        $codeOf = [];

        foreach ($codes as $code) {
            $code = trim($code);

            if (preg_match(self::ODOO_ID, $code) === 1 && (int) $code <= self::MOST_ID) {
                $codeOf[(int) $code] = $code;
            }
        }

        $this->calls = 0;
        $began = self::now();
        $refused = [];
        $refusal = null;
        $done = false;

        try {
            foreach (array_chunk(array_keys($codeOf), self::MOST_IDS) as $ids) {
                $asked = array_fill_keys($ids, true);

                foreach ($this->split($connection, $ids, $refused, $refusal) as [$id, $item]) {
                    // Only the ids asked: another the answer carries is left out, not reported (§2.4). One that
                    // comes twice is yielded twice: the read skips both and reports the size (§2.4).
                    if (isset($asked[$id])) {
                        yield self::value($codeOf[$id], $item);
                    }
                }
            }

            // Every id refused, more than one: the token's user may read none of them - the read fails as before.
            if ($refusal !== null && count($refused) > 1 && count($refused) === count($codeOf)) {
                throw $refusal;
            }

            foreach ($refused as $id => $status) {
                yield new ProviderValue($codeOf[$id], null, null, refused: $status);
            }

            $done = true;
        } finally {
            // One line a read, so a catalogue growing toward the job's time limit is seen (no code, no token).
            $this->log->info('Sync read Odoo.', [
                'store' => $connection->storeId,
                'ids' => count($codeOf),
                'calls' => $this->calls,
                'seconds' => round((self::now() - $began) / 1000, 1),
                'refused' => count($refused),
                'done' => $done,
            ]);
        }
    }

    /**
     * One call for these ids - or, when Odoo refuses it for one of them (`403` or another `4xx` but `401` and
     * `429`), the two halves asked apart, and so on down to the id refused, kept in `$refused` with the status.
     *
     * @param  list<int>  $ids
     * @param  array<int, int>  $refused  id => the status Odoo refused it with
     * @param  ConnectionFailed|null  $refusal  the first refusal, for a read whose every id was refused
     * @return list<array{int, array<array-key, mixed>}>
     *
     * @throws ConnectionFailed
     */
    private function split(Connection $connection, array $ids, array &$refused, ?ConnectionFailed &$refusal): array
    {
        try {
            return $this->post($connection, $ids, self::READ_SECONDS);
        } catch (ConnectionFailed $failed) {
            $status = self::refusedFor($failed);

            // Not an id's refusal - the token, their fault, a timeout - or so many ids refused it is the token.
            if ($status === null || count($refused) >= self::MOST_REFUSED) {
                throw $failed;
            }

            if (count($ids) === 1) {
                $refused[$ids[0]] = $status;
                $refusal ??= $failed;

                return [];
            }

            $half = intdiv(count($ids), 2);

            return [
                ...$this->split($connection, array_slice($ids, 0, $half), $refused, $refusal),
                ...$this->split($connection, array_slice($ids, $half), $refused, $refusal),
            ];
        }
    }

    /** The status of a refusal one id may cause: `403`, `400` or another `4xx` - never `401` (the token) or `429`. */
    private static function refusedFor(ConnectionFailed $failed): ?int
    {
        $status = $failed->coded?->values['status'] ?? null;

        return match ($failed->coded?->code) {
            ProviderReason::REFUSED_ACCESS => 403,
            ProviderReason::REFUSED_REQUEST => 400,
            ProviderReason::ANSWERED => is_int($status) && $status >= 400 && $status < 500 ? $status : null,
            default => null,
        };
    }

    /**
     * One call, at the store's pace; a `429` waited out and asked once more.
     *
     * @param  list<int>  $ids
     * @return list<array{int, array<array-key, mixed>}> each item with its id
     *
     * @throws ConnectionFailed
     */
    private function post(Connection $connection, array $ids, int $seconds, bool $waitWhenTooMany = true): array
    {
        // The address rule refuses another already (`ConnectionInput`); the token never leaves on plain HTTP.
        if (! str_starts_with(strtolower($connection->address), 'https://')) {
            throw self::failed(new ProviderReason(ProviderReason::NOT_HTTPS));
        }

        $waited = false;

        while (true) {
            $response = $this->call($connection, $ids, $seconds);

            if ($response->status() !== 429) {
                break;
            }

            if (! $waitWhenTooMany || $waited) {
                throw self::failed(new ProviderReason($waited ? ProviderReason::TOO_MANY_CALLS_TWICE : ProviderReason::TOO_MANY_CALLS));
            }

            $waited = true;
            Sleep::for(self::retryAfter($response))->seconds();
        }

        if ($response->status() !== 200) {
            throw self::failed(self::refused($response->status()));
        }

        // What the client's callbacks did not stop (an answer without its length, a test's fake): its length
        // said, then its body.
        $length = trim($response->header('Content-Length'));

        if ((preg_match('/\A\d{1,18}\z/', $length) === 1 && (int) $length > self::MOST_ANSWER_BYTES) || strlen($response->body()) > self::MOST_ANSWER_BYTES) {
            throw self::failed(new ProviderReason(ProviderReason::ANSWER_TOO_LARGE));
        }

        return self::answer($response->body());
    }

    /**
     * @param  list<int>  $ids
     *
     * @throws ConnectionFailed
     */
    private function call(Connection $connection, array $ids, int $seconds): Response
    {
        $this->pace($connection->storeId);
        $this->calls++;
        $this->tooLarge = false;

        try {
            return $this->http
                ->withToken($connection->key)
                ->acceptJson()
                ->asJson()
                // A redirect would carry the token to another address, or to plain HTTP.
                ->withoutRedirecting()
                ->connectTimeout(min($seconds, self::CONNECT_SECONDS))
                ->timeout($seconds)
                ->withOptions(['on_headers' => $this->headersCheck(...), 'progress' => $this->progressCheck(...)])
                ->post(rtrim($connection->address, '/').self::PATH, ['ids' => $ids, 'fields' => self::FIELDS]);
        } catch (OdooAnswerTooLarge) {
            throw self::failed(new ProviderReason(ProviderReason::ANSWER_TOO_LARGE));
        } catch (RequestException $error) {
            if ($this->stoppedTooLarge()) {
                throw self::failed(new ProviderReason(ProviderReason::ANSWER_TOO_LARGE));
            }

            // A status of 400 or more whose transfer then failed (a body too slow): judged by its status like
            // any answer - a 429 waited out - and its body, which its message holds, never read.
            return $error->response;
        } catch (ConnectionException $error) {
            // A transfer the callbacks stopped, as the client reports it.
            if ($this->stoppedTooLarge()) {
                throw self::failed(new ProviderReason(ProviderReason::ANSWER_TOO_LARGE));
            }

            // Its message holds the address: never passed on, nor chained.
            throw self::failed(self::timedOut($error, $seconds) ?? new ProviderReason(ProviderReason::UNREACHABLE));
        } catch (HttpClientException) {
            throw self::failed(new ProviderReason($this->stoppedTooLarge() ? ProviderReason::ANSWER_TOO_LARGE : ProviderReason::UNREACHABLE));
        } catch (InvalidArgumentException) {
            // A request that cannot be written - a control character in the key, say: never the key itself.
            throw self::failed(new ProviderReason(ProviderReason::UNSENDABLE));
        } finally {
            $this->lastCallEnded[$connection->storeId] = self::now();
        }
    }

    /** Whether the client's callbacks stopped this call's answer past `MOST_ANSWER_BYTES` - set by them, as it came. */
    private function stoppedTooLarge(): bool
    {
        return $this->tooLarge;
    }

    /**
     * The answer's headers, as they come: a `Content-Length` past `MOST_ANSWER_BYTES` stops it before its body.
     *
     * @throws OdooAnswerTooLarge
     */
    private function headersCheck(ResponseInterface $response): void
    {
        $length = trim($response->getHeaderLine('Content-Length'));

        if (preg_match('/\A\d{1,18}\z/', $length) === 1 && (int) $length > self::MOST_ANSWER_BYTES) {
            $this->tooLarge = true;

            throw new OdooAnswerTooLarge("Odoo's answer is too large.");
        }
    }

    /**
     * The transfer's progress, as it goes: past `MOST_ANSWER_BYTES` said or come, it stops.
     *
     * @throws OdooAnswerTooLarge
     */
    private function progressCheck(int|float $downloadTotal, int|float $downloaded): void
    {
        if ($downloadTotal > self::MOST_ANSWER_BYTES || $downloaded > self::MOST_ANSWER_BYTES) {
            $this->tooLarge = true;

            throw new OdooAnswerTooLarge("Odoo's answer is too large.");
        }
    }

    /** At least a second since the store's last call ended (§9.1 #8). */
    private function pace(string $storeId): void
    {
        $last = $this->lastCallEnded[$storeId] ?? null;
        $wait = $last === null ? 0 : (int) ceil(self::GAP_MILLISECONDS - (self::now() - $last));

        if ($wait > 0) {
            Sleep::for($wait)->milliseconds();
        }
    }

    /** Now, in milliseconds - Carbon's, so a test's frozen clock and faked sleeps keep it. */
    private static function now(): float
    {
        return Carbon::now()->getPreciseTimestamp(3);
    }

    /** `Retry-After` in seconds, 60 at most; unsaid or not a number, the most (§2.4). */
    private static function retryAfter(Response $response): int
    {
        $said = trim($response->header('Retry-After'));

        return preg_match('/\A\d{1,9}\z/', $said) === 1 ? min((int) $said, self::MOST_RETRY_AFTER) : self::MOST_RETRY_AFTER;
    }

    /**
     * The reason for a timeout, naming the time that ran out - reaching Odoo, or its answer - or null when
     * the failure was not one (cURL's error 28, or a message saying so).
     */
    private static function timedOut(ConnectionException $error, int $seconds): ?ProviderReason
    {
        $message = strtolower($error->getMessage());

        if (! str_contains($message, 'curl error 28') && ! str_contains($message, 'timed out')) {
            return null;
        }

        // cURL says "Connection timed out" or "Resolving timed out" while reaching it, "Operation timed out" after.
        return str_contains($message, 'connection timed out') || str_contains($message, 'resolving timed out')
            ? new ProviderReason(ProviderReason::UNREACHABLE_WITHIN, ['seconds' => min($seconds, self::CONNECT_SECONDS)])
            : new ProviderReason(ProviderReason::NO_ANSWER_WITHIN, ['seconds' => $seconds]);
    }

    /** What a status that is not `200` says, with its code (§2.4 errors). */
    private static function refused(int $status): ProviderReason
    {
        return match (true) {
            $status === 401 => new ProviderReason(ProviderReason::REFUSED_TOKEN),
            $status === 403 => new ProviderReason(ProviderReason::REFUSED_ACCESS),
            $status === 400 => new ProviderReason(ProviderReason::REFUSED_REQUEST),
            $status >= 500 => new ProviderReason(ProviderReason::FAILED, ['status' => $status]),
            default => new ProviderReason(ProviderReason::ANSWERED, ['status' => $status]),
        };
    }

    /**
     * The answer: a JSON list of objects, each with a whole-number `id` (§2.4) - each item with its id.
     *
     * @return list<array{int, array<array-key, mixed>}>
     *
     * @throws ConnectionFailed
     */
    private static function answer(string $body): array
    {
        try {
            // An id too long for a whole number kept as text - so refused below, never rounded to another id.
            $answer = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw self::failed(new ProviderReason(ProviderReason::NOT_JSON));
        }

        if (! is_array($answer) || ! array_is_list($answer)) {
            throw self::failed(new ProviderReason(ProviderReason::ANSWER_NOT_LIST));
        }

        $items = [];

        foreach ($answer as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (! is_array($item) || ! is_int($id)) {
                throw self::failed(new ProviderReason(ProviderReason::ITEM_WITHOUT_ID));
            }

            $items[] = [$id, $item];
        }

        return $items;
    }

    /**
     * One item as a value under our code: its price and its stock as they came - Pricing, Inventory and Sync
     * check them (§1.4, §1.5): `104.0` is a price, `577.0` a stock of 577, `false` (an empty field in Odoo) or a
     * fraction a bad value for that code alone. One null or left out is named `missing`, a bad value too -
     * the other still taken when it is good. Other fields ignored.
     *
     * @param  array<array-key, mixed>  $item
     */
    private static function value(string $code, array $item): ProviderValue
    {
        $price = $item['list_price'] ?? null;
        $stock = $item['free_qty'] ?? null;
        $missing = array_values(array_filter(['price' => $price === null ? 'price' : null, 'stock' => $stock === null ? 'stock' : null]));

        return new ProviderValue($code, $price, $stock, $missing);
    }

    /**
     * The failure kept as its code and values, its developers' message the English sentence the screens
     * write from the same lang file (`sync::provider_failures`) - never the stored JSON (the review of step 10).
     */
    private static function failed(ProviderReason $reason): ConnectionFailed
    {
        $sentence = trans("sync::provider_failures.{$reason->code}", $reason->values, 'en');

        return new ConnectionFailed($reason, is_string($sentence) ? $sentence : null);
    }
}
