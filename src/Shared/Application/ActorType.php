<?php

namespace Shared\Application;

enum ActorType: string
{
    case Staff = 'STAFF';
    case Customer = 'CUSTOMER';
    case System = 'SYSTEM';
}
