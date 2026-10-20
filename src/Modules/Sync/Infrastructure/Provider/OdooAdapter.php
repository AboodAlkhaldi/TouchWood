<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Provider;

use DateTimeImmutable;
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
use Modules\Sync\Domain\ValueObject\ProviderValue;

/**
 * **Odoo, read through the API its team builds for us** (sync.md §2.4, accepted 2026-10-19): HTTPS `GET`s
 * only, at the store's address (`…/touchwood/v1`), signed in with `Authorization: Bearer <key>`. Four
 * calls: every item paged by `after`, what changed since a time, named codes 500 a call, and the test.
 *
 * - **Pace**: one call at a time - the read waits for each answer - and at least 1 second between the end
 *   of a store's call and its next one (§9.1 #8). The adapter is one per worker (bound once), so a store's
 *   next read in the same worker keeps the pace too. The waits go through Laravel's `Sleep`, which a test
 *   fakes.
 * - **Errors**: a `429` waits `Retry-After` (60 seconds at most) and asks once more; anything else fails
 *   with a short sentence - what failed and its status code - never the provider's body, the address or
 *   the key (§2.1). The read's retries are `ReadStoreJob`'s (§1.7).
 * - **The test** is the one call made in a web request: 10 seconds at most (§9.3 #21), so a `429` there
 *   fails at once rather than wait.
 */
final class OdooAdapter implements ProviderAdapter
{
    /** Items a page (§2.4 call 1, 2). */
    public const int PAGE = 1000;

    /** Codes a call (§2.4 call 3); a longer list is asked in several calls. */
    public const int MOST_CODES = 500;

    /** How far their clock may be off: an hourly read asks from the last read's start less this (§2.4 call 2). */
    public const int CLOCK_SLACK_MINUTES = 5;

    /** Between a store's calls, at least (§9.1 #8). */
    public const int GAP_MILLISECONDS = 1000;

    /** A read's call waits this long at most (§2.4). */
    public const int READ_SECONDS = 30;

    /** The Super Admin's test waits this long at most: it runs in a web request (§9.3 #21). */
    public const int TEST_SECONDS = 10;

    /** Reaching Odoo - before any answer - takes this long at most, in a read and the test alike. */
    public const int CONNECT_SECONDS = 10;

    /** Pages a read asks at most: 200,000 items (§2.4 As built) - past them, the read fails. */
    public const int MOST_PAGES = 200;

    /** A `429`'s `Retry-After` is waited this long at most (§2.4). */
    public const int MOST_RETRY_AFTER = 60;

    /** Odoo's path: the store's address ends in it (§2.4). */
    public const string PATH = '/touchwood/v1';

    /** @var array<string, float> per store, when its last call ended - milliseconds */
    private array $lastCallEnded = [];

    public function __construct(
        private readonly Factory $http,
    ) {}

    /** Odoo asks for nothing beside the address, the key and the secret (§2.4). */
    public function fields(): array
    {
        return [];
    }

    /** The API of §2.4 is built against (Q38). */
    public function ready(): bool
    {
        return true;
    }

    /**
     * `https://<the account's address>/touchwood/v1` (§2.4): no query or fragment, the path ending in
     * `/touchwood/v1`, a closing slash dropped. The shared rule has checked HTTPS and the host already.
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

        $path = $parts['path'] ?? '';

        if (! str_ends_with($path, self::PATH)) {
            throw new InvalidSyncInput('address', rule: InvalidSyncInput::ADDRESS_PATH);
        }

        return $address;
    }

    /** `GET /items?limit=1` (§2.4 call 4). */
    public function test(Connection $connection): void
    {
        $this->get($connection, ['limit' => '1'], self::TEST_SECONDS, waitWhenTooMany: false);
    }

    /**
     * Named codes (call 3), what changed since a time (call 2), or every item (call 1) - one value a code
     * as Odoo wrote it; the codes it lists as `missing` are simply not there.
     */
    public function read(Connection $connection, ?array $codes, ?DateTimeImmutable $changedSince): iterable
    {
        if ($codes !== null) {
            return $this->named($connection, $codes);
        }

        // From the last read's start less the most their clock may be off, so a change at the edge is read
        // twice, never missed (§2.4 call 2).
        return $this->paged($connection, $changedSince === null ? [] : [
            'changed_since' => Carbon::instance($changedSince)->utc()->subMinutes(self::CLOCK_SLACK_MINUTES)->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Calls 1 and 2: page after page, until `next` is null.
     *
     * @param  array<string, string>  $query
     * @return Generator<int, ProviderValue>
     */
    private function paged(Connection $connection, array $query): Generator
    {
        $after = null;
        $asked = [];
        $pages = 0;

        do {
            // A read has an end: past 200 pages (200,000 items), it fails rather than ask on for ever.
            if (++$pages > self::MOST_PAGES) {
                throw new ConnectionFailed('Odoo\'s items run past '.self::MOST_PAGES.' pages.');
            }

            $answer = $this->get($connection, [...$query, 'limit' => (string) self::PAGE, ...($after === null ? [] : ['after' => $after])], self::READ_SECONDS);
            $values = self::values($answer);

            yield from $values;

            $after = self::next($answer);

            if ($after !== null) {
                // A page with nothing on it still naming a next one, or a page asked before: their pages would
                // go round for ever.
                if ($values === []) {
                    throw self::malformed('an empty page names a next one');
                }

                if (isset($asked[$after])) {
                    throw self::malformed('its pages repeat');
                }

                $asked[$after] = true;
            }
        } while ($after !== null);
    }

    /**
     * Call 3: the codes, 500 a call.
     *
     * @param  list<string>  $codes
     * @return Generator<int, ProviderValue>
     */
    private function named(Connection $connection, array $codes): Generator
    {
        // Trimmed, then each once, before the calls are cut: " 7 " and "7" are one code (§1.1 rule 5). A code
        // holding a comma cannot be named in the list: it is not asked - the full and hourly reads cover it.
        $codes = array_values(array_unique(array_filter(
            array_map(trim(...), $codes),
            static fn (string $code): bool => $code !== '' && ! str_contains($code, ','),
        )));

        foreach (array_chunk($codes, self::MOST_CODES) as $some) {
            $asked = array_fill_keys($some, true);

            // Only the codes asked: another the answer carries is left out, not reported (§2.4). Its `next`
            // is not followed - a call of 500 codes fits one page.
            foreach (self::values($this->get($connection, ['codes' => implode(',', $some)], self::READ_SECONDS)) as $value) {
                if (isset($asked[trim($value->code)])) {
                    yield $value;
                }
            }
        }
    }

    /**
     * One call, at the store's pace; a `429` waited out and asked once more.
     *
     * @param  array<string, string>  $query
     * @return array<mixed>
     *
     * @throws ConnectionFailed
     */
    private function get(Connection $connection, array $query, int $seconds, bool $waitWhenTooMany = true): array
    {
        // The address rule refuses another already (`ConnectionInput`); the token never leaves on plain HTTP.
        if (! str_starts_with(strtolower($connection->address), 'https://')) {
            throw new ConnectionFailed('The address must start with https://.');
        }

        $waited = false;

        while (true) {
            $response = $this->call($connection, $query, $seconds);

            if ($response->status() !== 429) {
                break;
            }

            if (! $waitWhenTooMany || $waited) {
                throw new ConnectionFailed($waited ? 'Odoo answered 429 (too many calls) twice.' : 'Odoo answered 429 (too many calls).');
            }

            $waited = true;
            Sleep::for(self::retryAfter($response))->seconds();
        }

        if ($response->status() !== 200) {
            throw new ConnectionFailed(self::refused($response->status()));
        }

        return self::answer($response->body());
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws ConnectionFailed
     */
    private function call(Connection $connection, array $query, int $seconds): Response
    {
        $this->pace($connection->storeId);

        try {
            return $this->http
                ->baseUrl($connection->address)
                ->withToken($connection->key)
                ->acceptJson()
                // A redirect would carry the token to another address, or to plain HTTP.
                ->withoutRedirecting()
                ->connectTimeout(min($seconds, self::CONNECT_SECONDS))
                ->timeout($seconds)
                ->get('items?'.self::query($query));
        } catch (RequestException $error) {
            // A status of 400 or more whose transfer then failed (a body too slow): judged by its status like
            // any answer - a 429 waited out - and its body, which its message holds, never read.
            return $error->response;
        } catch (ConnectionException $error) {
            // Its message holds the address: never passed on, nor chained.
            throw new ConnectionFailed(self::timedOut($error, $seconds) ?? 'Odoo could not be reached.');
        } catch (HttpClientException) {
            throw new ConnectionFailed('Odoo could not be reached.');
        } catch (InvalidArgumentException) {
            // A request that cannot be written - a control character in the key, say: never the key itself.
            throw new ConnectionFailed('Odoo could not be asked: the address or the key holds a character that cannot be sent.');
        } finally {
            $this->lastCallEnded[$connection->storeId] = self::now();
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

    /**
     * The query as §2.4 writes it: its commas and colons left as they are.
     *
     * @param  array<string, string>  $query
     */
    private static function query(array $query): string
    {
        $pairs = [];

        foreach ($query as $name => $value) {
            $pairs[] = $name.'='.strtr(rawurlencode($value), ['%2C' => ',', '%3A' => ':']);
        }

        return implode('&', $pairs);
    }

    /** `Retry-After` in seconds, 60 at most; unsaid or not a number, the most (§2.4). */
    private static function retryAfter(Response $response): int
    {
        $said = trim($response->header('Retry-After'));

        return preg_match('/\A\d{1,9}\z/', $said) === 1 ? min((int) $said, self::MOST_RETRY_AFTER) : self::MOST_RETRY_AFTER;
    }

    /**
     * The sentence for a timeout, naming the time that ran out - reaching Odoo, or its answer - or null when
     * the failure was not one (cURL's error 28, or a message saying so).
     */
    private static function timedOut(ConnectionException $error, int $seconds): ?string
    {
        $message = strtolower($error->getMessage());

        if (! str_contains($message, 'curl error 28') && ! str_contains($message, 'timed out')) {
            return null;
        }

        // cURL says "Connection timed out" or "Resolving timed out" while reaching it, "Operation timed out" after.
        return str_contains($message, 'connection timed out') || str_contains($message, 'resolving timed out')
            ? 'Odoo could not be reached within '.min($seconds, self::CONNECT_SECONDS).' seconds.'
            : "Odoo did not answer within {$seconds} seconds.";
    }

    /** What a status that is not `200` says, with its code (§2.4 errors). */
    private static function refused(int $status): string
    {
        return match (true) {
            $status === 401 => 'Odoo refused the token (401).',
            $status === 400 => 'Odoo refused the request (400).',
            $status >= 500 => "Odoo failed ({$status}).",
            default => "Odoo answered {$status}.",
        };
    }

    /**
     * The answer's JSON object.
     *
     * @return array<mixed>
     *
     * @throws ConnectionFailed
     */
    private static function answer(string $body): array
    {
        try {
            // A long number kept as written, as text (a code).
            $answer = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw self::malformed('it is not JSON');
        }

        if (! is_array($answer) || ! array_key_exists('items', $answer) || ! is_array($answer['items']) || ! array_is_list($answer['items'])) {
            throw self::malformed('its items are not a list');
        }

        return $answer;
    }

    /**
     * Each item as a value: its code as text exactly as written (a number taken as its digits), its price
     * and its stock as they came - Pricing, Inventory and Sync check them (§1.4, §1.5). Other fields ignored.
     *
     * @param  array<mixed>  $answer
     * @return list<ProviderValue>
     *
     * @throws ConnectionFailed
     */
    private static function values(array $answer): array
    {
        $values = [];

        /** @var list<mixed> $items */
        $items = $answer['items'];

        foreach ($items as $item) {
            $code = is_array($item) ? ($item['code'] ?? null) : null;
            $code = is_int($code) ? (string) $code : $code;

            if (! is_string($code) || trim($code) === '') {
                throw self::malformed('an item has no code');
            }

            /** @var array<string, mixed> $item */
            $values[] = new ProviderValue($code, $item['price'] ?? null, $item['available'] ?? null);
        }

        return $values;
    }

    /**
     * The next page's `after`, or null on the last page.
     *
     * @param  array<mixed>  $answer
     *
     * @throws ConnectionFailed
     */
    private static function next(array $answer): ?string
    {
        $next = $answer['next'] ?? null;

        return match (true) {
            // Odoo writes an empty field as `false`: the last page, as null is.
            $next === null, $next === false => null,
            is_int($next) => (string) $next,
            is_string($next) && $next !== '' => $next,
            default => throw self::malformed('its next page is not text'),
        };
    }

    private static function malformed(string $what): ConnectionFailed
    {
        return new ConnectionFailed("Odoo's answer is not as agreed: {$what}.");
    }
}
