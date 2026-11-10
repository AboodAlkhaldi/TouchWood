<?php

declare(strict_types=1);

namespace Tests\Modules\Sync\Support;

use Modules\Sync\Application\Connections\HostAddresses;

/**
 * Hosts as a test resolves them, never through DNS (tests/TestCase.php binds it for every test): an IP written
 * as the host is itself; a name resolves to what the test gave it in `names`, else to nothing.
 */
final class FakeHostAddresses implements HostAddresses
{
    /** @var array<string, list<string>> host name => its addresses */
    public array $names = [];

    public static function bound(): self
    {
        $bound = app(HostAddresses::class);

        return $bound instanceof self ? $bound : throw new \LogicException('FakeHostAddresses is not bound.');
    }

    public function of(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->names[strtolower($host)] ?? [];
    }
}
