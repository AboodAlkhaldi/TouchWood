<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelAccountDeletion;

/**
 * The customer changed their mind while still signed in (spec §1.10). Signing in cancels a deletion
 * by itself; this is for the account page, where they never signed out.
 */
final readonly class CancelAccountDeletion {}
