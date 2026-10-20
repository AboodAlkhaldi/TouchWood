<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Connections;

use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\ValueObject\ProviderKind;

/**
 * A connection's fields as the Super Admin wrote them (sync.md §1.2, §7): a provider we have, an HTTPS
 * address, a key and a secret that are not empty, and only the fields the provider asks for - each
 * refusal naming its field, never repeating the key or the secret.
 */
final readonly class ConnectionInput
{
    public const int MOST_ADDRESS = 500;

    public const int MOST_SECRET = 1000;

    public function __construct(
        private ProviderAdapters $adapters,
    ) {}

    public function provider(string $provider): ProviderKind
    {
        return ProviderKind::tryFrom(strtoupper(trim($provider))) ?? throw new InvalidSyncInput('provider');
    }

    /**
     * HTTPS with a host and nothing else of a person's, then the provider's own shape (Odoo's: no query or
     * fragment, its path ending in `/touchwood/v1`, sync.md §2.4) - each refused by its own rule's words.
     */
    public function address(ProviderKind $provider, string $address): string
    {
        $address = trim($address);

        if (preg_match('#\Ahttps://#i', $address) !== 1) {
            throw new InvalidSyncInput('address', rule: InvalidSyncInput::ADDRESS_HTTPS);
        }

        // A name or a password in the address would be audited and shown: the key is for that.
        if (mb_strlen($address) > self::MOST_ADDRESS || preg_match('#\Ahttps://[^\s/?\#@]+([/?\#][^\s]*)?\z#i', $address) !== 1) {
            throw new InvalidSyncInput('address');
        }

        // The table holds the scheme as written in lower case (its CHECK, `connections_address`).
        $address = 'https://'.substr($address, strlen('https://'));

        // One the HTTP client can call: a host it reads (no "\" in it), a port from 1 (parse_url refuses one past 65535).
        $parts = parse_url($address);
        $port = is_array($parts) ? ($parts['port'] ?? 443) : 0;

        if (filter_var($address, FILTER_VALIDATE_URL) === false || ! is_array($parts) || ($parts['host'] ?? '') === '' || $port < 1) {
            throw new InvalidSyncInput('address', rule: InvalidSyncInput::ADDRESS_MALFORMED);
        }

        return $this->adapters->for($provider)->address($address);
    }

    /**
     * @param  'key'|'secret'  $field
     */
    public function secret(#[\SensitiveParameter] string $value, string $field): string
    {
        if (trim($value) === '' || mb_strlen($value) > self::MOST_SECRET) {
            throw new InvalidSyncInput($field);
        }

        return $value;
    }

    /**
     * Every field the provider asks for, as text, and no other.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array<string, string>
     */
    public function settings(ProviderKind $provider, array $settings): array
    {
        $asked = $this->adapters->for($provider)->fields();
        $kept = [];

        // Each refused by its own name (Q34): one not asked for, empty or not text, or one left out.
        foreach ($settings as $field => $value) {
            if (! in_array($field, $asked, true) || ! is_string($value) || trim($value) === '') {
                throw new InvalidSyncInput(InvalidSyncInput::SETTINGS, mb_substr((string) $field, 0, 100));
            }

            $kept[$field] = $value;
        }

        foreach ($asked as $field) {
            if (! array_key_exists($field, $kept)) {
                throw new InvalidSyncInput(InvalidSyncInput::SETTINGS, $field);
            }
        }

        return $kept;
    }
}
