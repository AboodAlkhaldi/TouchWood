<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Network;

use Modules\Sync\Application\Connections\HostAddresses;

/** A host's addresses from DNS - its A and AAAA records - or the IP itself when one is written. */
final class DnsHostAddresses implements HostAddresses
{
    public function of(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = @gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
