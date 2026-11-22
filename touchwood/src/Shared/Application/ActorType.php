<?php

declare(strict_types=1);

namespace Shared\Application;

enum ActorType: string
{
    case Staff = 'STAFF';
    case Customer = 'CUSTOMER';
    /** A visitor without an account: browses and fills a cart, and must register to order. */
    case Guest = 'GUEST';
    /** An outside system acting through one of our integrations (inventory sync, payment webhooks). */
    case Integration = 'INTEGRATION';
    case System = 'SYSTEM';
}
