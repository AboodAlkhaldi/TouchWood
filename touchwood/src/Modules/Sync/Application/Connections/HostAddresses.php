<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Connections;

/**
 * The IP addresses a host name resolves to, for the address rule (sync.md §2.4): an IP written as the host is
 * itself. A name that resolves to nothing gives none - its test call then fails as Odoo not reached. Bound to
 * DNS; a test binds its own, so no test asks DNS.
 */
interface HostAddresses
{
    /**
     * @return list<string> IPv4 and IPv6 addresses, as text
     */
    public function of(string $host): array;
}
