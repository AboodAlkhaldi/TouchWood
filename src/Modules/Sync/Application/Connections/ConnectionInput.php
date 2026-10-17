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

    public function address(string $address): string
    {
        $address = trim($address);

        // A name or a password in the address would be audited and shown: the key is for that.
        if (mb_strlen($address) > self::MOST_ADDRESS || preg_match('#\Ahttps://[^\s/?\#@]+([/?\#][^\s]*)?\z#i', $address) !== 1) {
            throw new InvalidSyncInput('address');
        }

        // The table holds the scheme as written in lower case (its CHECK, `connections_address`).
        return 'https://'.substr($address, strlen('https://'));
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

        foreach ($settings as $field => $value) {
            if (! in_array($field, $asked, true) || ! is_string($value) || trim($value) === '') {
                throw new InvalidSyncInput('provider');
            }

            $kept[$field] = $value;
        }

        if (count($kept) !== count($asked)) {
            throw new InvalidSyncInput('provider');
        }

        return $kept;
    }
}
