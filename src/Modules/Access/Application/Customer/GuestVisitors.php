<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

/**
 * The guest this browser has been so far (spec §1.7): an id in an encrypted, HTTP-only cookie,
 * created the first time something needs it — the first cart line, in Sales — never on a page view,
 * so bots create nothing. Access only reads it here, to say that this guest became that customer.
 */
interface GuestVisitors
{
    /**
     * The guest id this request carries, or null when the browser has none yet.
     */
    public function current(): ?string;
}
