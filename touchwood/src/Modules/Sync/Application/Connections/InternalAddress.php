<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Connections;

/**
 * Whether an IP is one a provider's address may never reach (sync.md §2.4): this machine's own (loopback,
 * unspecified), a private network's (RFC 1918, IPv6 unique-local) or a link's (link-local) - so a Super Admin
 * cannot point the adapter, and the key it sends, at a service inside our network. An IPv4 address written as
 * IPv6 (`::ffff:10.0.0.1`) is judged as the IPv4 it holds. Text that is not an IP counts as internal.
 */
final class InternalAddress
{
    /** @var list<array{string, int}> each range's first address and its prefix length */
    private const array RANGES = [
        ['0.0.0.0', 8],        // unspecified, "this network"
        ['10.0.0.0', 8],       // private (RFC 1918)
        ['127.0.0.0', 8],      // loopback
        ['169.254.0.0', 16],   // link-local
        ['172.16.0.0', 12],    // private (RFC 1918)
        ['192.168.0.0', 16],   // private (RFC 1918)
        ['::', 128],           // unspecified
        ['::1', 128],          // loopback
        ['fc00::', 7],         // unique-local
        ['fe80::', 10],        // link-local
    ];

    public static function is(string $ip): bool
    {
        $bytes = @inet_pton($ip);

        if ($bytes === false) {
            return true;
        }

        // An IPv4 address mapped into IPv6 (::ffff:a.b.c.d): its last four bytes.
        if (strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            $bytes = substr($bytes, 12);
        }

        foreach (self::RANGES as [$first, $prefix]) {
            $range = (string) inet_pton($first);

            if (strlen($range) === strlen($bytes) && self::within($bytes, $range, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function within(string $bytes, string $range, int $prefix): bool
    {
        $whole = intdiv($prefix, 8);

        if (substr($bytes, 0, $whole) !== substr($range, 0, $whole)) {
            return false;
        }

        $bits = $prefix % 8;

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($bytes[$whole]) & $mask) === (ord($range[$whole]) & $mask);
    }
}
