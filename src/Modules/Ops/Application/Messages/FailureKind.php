<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/** What a provider's failure means for the job that met it (ops.md §7; ProviderFailureRules). */
enum FailureKind
{
    /** It may pass by itself, or nothing says otherwise: the job's own attempts. */
    case MayPass;

    /** The provider refused this one address. Asking again cannot help, and nobody else is affected: failed at once. */
    case OneAddress;

    /** Nothing can go until a person mends the key, the credit, the IP or the sender: a long wait, for up to a day. */
    case WaitsForPerson;

    /** Too many calls: back after the wait named, no attempt spent. */
    case RateLimited;

    /** The contact's list is gone at the provider: the kept id is forgotten, and the next try makes a new one. */
    case ListGone;
}
