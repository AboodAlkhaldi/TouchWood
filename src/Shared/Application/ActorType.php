<?php

declare(strict_types=1);

namespace Shared\Application;

enum ActorType: string
{
    case Staff = 'STAFF';
    case Customer = 'CUSTOMER';
    case System = 'SYSTEM';
}
