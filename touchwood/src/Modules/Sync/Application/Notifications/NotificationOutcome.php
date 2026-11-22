<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Notifications;

/**
 * What a provider's notice came to (sync.md §1.3): its read queued, or refused - not who it claims to
 * be, or not a list of codes.
 */
enum NotificationOutcome
{
    case Queued;
    case Refused;
    case Invalid;
}
